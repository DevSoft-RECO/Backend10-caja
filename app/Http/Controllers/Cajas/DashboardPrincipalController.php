<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Denominacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardPrincipalController extends Controller
{
    public function saldosAgencias(Request $request)
    {
        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();

        $agencias = \App\Models\Agencia::orderBy('nombre')->get();
        $denominaciones = Denominacion::where('activo', true)->orderBy('valor', 'desc')->get();

        $resultado = [];

        foreach ($agencias as $agencia) {
            $agenciaId = $agencia->id;

            // Obtener cajas de la agencia seleccionada y denominaciones activas
            $cajas = Caja::where('estado', true)
                ->where('agencia_id', $agenciaId)
                ->get();

            if ($cajas->isEmpty()) {
                $resultado[] = [
                    'id' => $agencia->id,
                    'nombre' => $agencia->nombre,
                    'saldo_disponible' => 0.00
                ];
                continue;
            }

            // 2. Query de agregación agrupada para movimientos de hoy (Flujo regular) de la agencia
            $movimientosHoy = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                ->whereIn('movimientos.categoria_movimiento', ['abastecimiento', 'devolucion', 'cajilla_apertura', 'cajilla_cierre', 'cierre_jornada_barrido', 'deteriorado', 'traslado_boveda', 'bancos_extraccion', 'bancos_inyeccion'])
                ->where(function($query) use ($cajas) {
                    $cajaIds = $cajas->pluck('id');
                    $query->whereIn('movimientos.origen_caja_id', $cajaIds)
                          ->orWhereIn('movimientos.destino_caja_id', $cajaIds);
                })
                ->select(
                    'movimientos.origen_caja_id',
                    'movimientos.destino_caja_id',
                    'movimientos.categoria_movimiento',
                    'movimientos.tipo_operacion',
                    'movimiento_detalles.denominacion_id',
                    'movimiento_detalles.estado_dinero',
                    DB::raw('SUM(movimiento_detalles.cantidad) as total_cantidad'),
                    DB::raw('SUM(movimiento_detalles.subtotal) as total_subtotal')
                )
                ->groupBy(
                    'movimientos.origen_caja_id',
                    'movimientos.destino_caja_id',
                    'movimientos.categoria_movimiento',
                    'movimientos.tipo_operacion',
                    'movimiento_detalles.denominacion_id',
                    'movimiento_detalles.estado_dinero'
                )
                ->get();

            // 3. Estructurar matriz consolidada
            $matriz = [];
            $cajasCerradasHoy = [];

            // Validar primero si la Bóveda de la sucursal ya cerró hoy para arrastrar el cierre general de toda la agencia
            $bovedaAgencia = $cajas->firstWhere('tipo_caja', 'boveda');
            $bovedaCerradaHoy = false;
            if ($bovedaAgencia) {
                $bovedaCerradaHoy = \App\Models\CierreDiario::where('caja_id', $bovedaAgencia->id)
                    ->where('fecha_cierre', $start->toDateString())
                    ->exists();
            }
            
            foreach ($cajas as $caja) {
                $matriz[$caja->id] = [];
                
                if ($bovedaCerradaHoy) {
                    $cajasCerradasHoy[$caja->id] = true;
                }

                // Validar si ya existe un cierre consolidado hoy para esta caja específica
                $cierreHoy = \App\Models\CierreDiario::where('caja_id', $caja->id)
                    ->where('fecha_cierre', $start->toDateString())
                    ->with('detalles')
                    ->first();

                $ultimoCierre = null;
                if ($cierreHoy && ($caja->tipo_caja === 'boveda' || $bovedaCerradaHoy)) {
                    $ultimoCierre = $cierreHoy;
                    $cajasCerradasHoy[$caja->id] = true;
                } else {
                    $ultimoCierre = \App\Models\CierreDiario::where('caja_id', $caja->id)
                        ->where('fecha_cierre', '<', $start->toDateString())
                        ->with('detalles')
                        ->orderBy('fecha_cierre', 'desc')
                        ->orderBy('id', 'desc')
                        ->first();
                }

                foreach ($denominaciones as $denom) {
                    $cantInicialBueno = 0;
                    
                    if ($ultimoCierre) {
                        $cantInicialBueno = $ultimoCierre->detalles
                            ->where('denominacion_id', $denom->id)
                            ->where('estado_dinero', 'bueno')
                            ->sum('cantidad');
                    }

                    $matriz[$caja->id][$denom->id] = [
                        'saldo_inicial_cantidad' => (int)$cantInicialBueno,
                        'ingresos_cantidad' => 0,
                        'egresos_cantidad' => 0,
                    ];
                }
            }

            // Procesar los agregados
            foreach ($movimientosHoy as $item) {
                if ($item->origen_caja_id && isset($cajasCerradasHoy[$item->origen_caja_id])) {
                    $item->origen_caja_id = null;
                }
                if ($item->destino_caja_id && isset($cajasCerradasHoy[$item->destino_caja_id])) {
                    $item->destino_caja_id = null;
                }

                $denomId = $item->denominacion_id;
                $cant = (int) $item->total_cantidad;
                $estado = $item->estado_dinero;
                $categoria = $item->categoria_movimiento;

                if ($estado === 'bueno') {
                    if (in_array($categoria, ['traslado_boveda', 'bancos_extraccion', 'bancos_inyeccion'])) {
                        $tipoMov = $item->tipo_operacion;
                        if ($tipoMov === 'ingreso') {
                            if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                                $matriz[$item->destino_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                            }
                            if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                                $matriz[$item->origen_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                            }
                        }
                        if ($tipoMov === 'egreso') {
                            if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                                $matriz[$item->origen_caja_id][$denomId]['egresos_cantidad'] += $cant;
                            }
                            if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                                $matriz[$item->destino_caja_id][$denomId]['egresos_cantidad'] += $cant;
                            }
                        }
                    } elseif ($categoria === 'abastecimiento') {
                        if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                            $matriz[$item->origen_caja_id][$denomId]['egresos_cantidad'] += $cant;
                        }
                        if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                            $matriz[$item->destino_caja_id][$denomId]['egresos_cantidad'] += $cant;
                        }
                    } elseif ($categoria === 'devolucion') {
                        if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                            $matriz[$item->destino_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                        }
                        if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                            $matriz[$item->origen_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                        }
                    } else {
                        if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                            $matriz[$item->destino_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                        }
                        if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                            $matriz[$item->origen_caja_id][$denomId]['egresos_cantidad'] += $cant;
                        }
                    }
                }
            }

            // 4. Calcular el flujo de Cajillas (Aperturas y Cierres únicamente)
            $movimientosCajillas = DB::table('movimientos')
                ->whereBetween('fecha_transaccion', [$start, $end])
                ->whereIn('categoria_movimiento', ['cajilla_apertura', 'cajilla_cierre'])
                ->where(function($query) use ($cajas) {
                    $cajaIds = $cajas->pluck('id');
                    $query->whereIn('origen_caja_id', $cajaIds)
                          ->orWhereIn('destino_caja_id', $cajaIds);
                })
                ->select('origen_caja_id', 'destino_caja_id', 'categoria_movimiento', DB::raw('SUM(monto_total) as total'))
                ->groupBy('origen_caja_id', 'destino_caja_id', 'categoria_movimiento')
                ->get();

            $totalesCajillas = [];
            foreach ($cajas as $caja) {
                $saldoInicialCajillas = 0.00;
                if ($caja->tipo_caja === 'boveda') {
                    $cierreHoy = \App\Models\CierreDiario::where('caja_id', $caja->id)
                        ->where('fecha_cierre', $start->toDateString())
                        ->first();
                    if ($cierreHoy) {
                        $saldoInicialCajillas = (float) $cierreHoy->saldo_final_cajillas;
                    } else {
                        $ultimoCierreBoveda = \App\Models\CierreDiario::where('caja_id', $caja->id)
                            ->where('fecha_cierre', '<', $start->toDateString())
                            ->orderBy('fecha_cierre', 'desc')
                            ->orderBy('id', 'desc')
                            ->first();
                        if ($ultimoCierreBoveda) {
                            $saldoInicialCajillas = (float) $ultimoCierreBoveda->saldo_final_cajillas;
                        }
                    }
                }

                $totalesCajillas[$caja->id] = [
                    'saldo_inicial' => (float)$saldoInicialCajillas,
                    'ingresos' => 0.00,
                    'egresos' => 0.00
                ];
            }
            foreach ($movimientosCajillas as $item) {
                if ($item->origen_caja_id && isset($cajasCerradasHoy[$item->origen_caja_id])) {
                    $item->origen_caja_id = null;
                }
                if ($item->destino_caja_id && isset($cajasCerradasHoy[$item->destino_caja_id])) {
                    $item->destino_caja_id = null;
                }

                $total = (float) $item->total;
                $categoria = $item->categoria_movimiento;

                if ($item->origen_caja_id && isset($totalesCajillas[$item->origen_caja_id])) {
                    if ($categoria === 'cajilla_apertura') {
                        $totalesCajillas[$item->origen_caja_id]['egresos'] += $total;
                    }
                    if ($categoria === 'cajilla_cierre') {
                        $totalesCajillas[$item->origen_caja_id]['ingresos'] += $total;
                    }
                }
                if ($item->destino_caja_id && isset($totalesCajillas[$item->destino_caja_id])) {
                    if ($categoria === 'cajilla_apertura') {
                        $totalesCajillas[$item->destino_caja_id]['egresos'] += $total;
                    }
                    if ($categoria === 'cajilla_cierre') {
                        $totalesCajillas[$item->destino_caja_id]['ingresos'] += $total;
                    }
                }
            }

            // 5. Calcular el flujo de Deteriorados
            $movimientosDeteriorados = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                ->where('movimiento_detalles.estado_dinero', 'deteriorado')
                ->whereIn('movimientos.categoria_movimiento', ['deteriorado', 'bancos_extraccion'])
                ->where(function($query) use ($cajas) {
                    $cajaIds = $cajas->pluck('id');
                    $query->whereIn('movimientos.origen_caja_id', $cajaIds)
                          ->orWhereIn('movimientos.destino_caja_id', $cajaIds);
                })
                ->select('movimientos.origen_caja_id', 'movimientos.destino_caja_id', 'movimientos.categoria_movimiento', DB::raw('SUM(movimiento_detalles.subtotal) as total'))
                ->groupBy('movimientos.origen_caja_id', 'movimientos.destino_caja_id', 'movimientos.categoria_movimiento')
                ->get();

            $totalesDeteriorados = [];
            foreach ($cajas as $caja) {
                $saldoInicialDeteriorados = 0.00;
                if ($caja->tipo_caja === 'boveda') {
                    $cierreHoy = \App\Models\CierreDiario::where('caja_id', $caja->id)
                        ->where('fecha_cierre', $start->toDateString())
                        ->first();
                    if ($cierreHoy) {
                        $saldoInicialDeteriorados = (float) $cierreHoy->saldo_final_deteriorado;
                    } else {
                        $ultimoCierreBoveda = \App\Models\CierreDiario::where('caja_id', $caja->id)
                            ->where('fecha_cierre', '<', $start->toDateString())
                            ->orderBy('fecha_cierre', 'desc')
                            ->orderBy('id', 'desc')
                            ->first();
                        if ($ultimoCierreBoveda) {
                            $saldoInicialDeteriorados = (float) $ultimoCierreBoveda->saldo_final_deteriorado;
                        }
                    }
                }

                $totalesDeteriorados[$caja->id] = [
                    'saldo_inicial' => (float)$saldoInicialDeteriorados,
                    'ingresos' => 0.00,
                    'egresos' => 0.00
                ];
            }
            foreach ($movimientosDeteriorados as $item) {
                if ($item->origen_caja_id && isset($cajasCerradasHoy[$item->origen_caja_id])) {
                    $item->origen_caja_id = null;
                }
                if ($item->destino_caja_id && isset($cajasCerradasHoy[$item->destino_caja_id])) {
                    $item->destino_caja_id = null;
                }

                $total = (float) $item->total;
                $cat = $item->categoria_movimiento;

                if ($cat === 'bancos_extraccion') {
                    if ($item->origen_caja_id && isset($totalesDeteriorados[$item->origen_caja_id])) {
                        $totalesDeteriorados[$item->origen_caja_id]['egresos'] += $total;
                    }
                    if ($item->destino_caja_id && isset($totalesDeteriorados[$item->destino_caja_id])) {
                        $totalesDeteriorados[$item->destino_caja_id]['egresos'] += $total;
                    }
                } else {
                    if ($item->origen_caja_id && isset($totalesDeteriorados[$item->origen_caja_id])) {
                        $caja = $cajas->firstWhere('id', $item->origen_caja_id);
                        if ($caja && $caja->tipo_caja === 'ventanilla') {
                            $totalesDeteriorados[$item->origen_caja_id]['ingresos'] += $total;
                        } else {
                            $totalesDeteriorados[$item->origen_caja_id]['egresos'] += $total;
                        }
                    }
                    if ($item->destino_caja_id && isset($totalesDeteriorados[$item->destino_caja_id])) {
                        $caja = $cajas->firstWhere('id', $item->destino_caja_id);
                        if ($caja && $caja->tipo_caja === 'boveda') {
                            $totalesDeteriorados[$item->destino_caja_id]['ingresos'] += $total;
                        } else {
                            $totalesDeteriorados[$item->destino_caja_id]['egresos'] += $total;
                        }
                    }
                }
            }

            // Calcular saldo disponible consolidando solo la Bóveda (tipo_caja === 'boveda') de la agencia,
            // tal y como funciona el Total General del Dashboard General
            $saldoAgenciaTotal = 0.00;
            foreach ($cajas as $caja) {
                if ($caja->tipo_caja !== 'boveda') {
                    continue;
                }
                
                // Sumar disponible por denominación
                $saldoCajaDenoms = 0.00;
                foreach ($denominaciones as $denom) {
                    $row = $matriz[$caja->id][$denom->id] ?? null;
                    if ($row) {
                        $cantDisponible = $row['saldo_inicial_cantidad'] + $row['ingresos_cantidad'] - $row['egresos_cantidad'];
                        $saldoCajaDenoms += $cantDisponible * $denom->valor;
                    }
                }

                // Sumar Cajillas y Deteriorado si es boveda
                $saldoCajaEspeciales = 0.00;
                // Cajillas
                $cajillasData = $totalesCajillas[$caja->id] ?? null;
                if ($cajillasData) {
                    $saldoCajaEspeciales += ($cajillasData['saldo_inicial'] + $cajillasData['ingresos'] - $cajillasData['egresos']);
                }
                // Deteriorados
                $detData = $totalesDeteriorados[$caja->id] ?? null;
                if ($detData) {
                    $saldoCajaEspeciales += ($detData['saldo_inicial'] + $detData['ingresos'] - $detData['egresos']);
                }

                $saldoAgenciaTotal += ($saldoCajaDenoms + $saldoCajaEspeciales);
            }

            $resultado[] = [
                'id' => $agencia->id,
                'nombre' => $agencia->nombre,
                'saldo_disponible' => (float)$saldoAgenciaTotal
            ];
        }

        return response()->json($resultado);
    }
}
