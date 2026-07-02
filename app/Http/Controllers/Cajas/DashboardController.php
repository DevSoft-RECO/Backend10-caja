<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Denominacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function dashboardGeneral(Request $request)
    {
        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();

        // 1. Obtener la agencia seleccionada (o tomar la primera por defecto)
        $agenciaId = $request->query('agencia_id');
        if (!$agenciaId) {
            $primeraCaja = Caja::where('estado', true)->first();
            $agenciaId = $primeraCaja ? $primeraCaja->agencia_id : null;
        }

        // Obtener cajas de la agencia seleccionada y denominaciones activas
        $cajas = Caja::with('agencia')
            ->where('estado', true)
            ->when($agenciaId, function($q) use ($agenciaId) {
                return $q->where('agencia_id', $agenciaId);
            })
            ->get();
        $denominaciones = Denominacion::where('activo', true)->orderBy('valor', 'desc')->get();

        // 2. Query de agregación agrupada para movimientos de hoy (Flujo regular)
        $movimientosHoy = DB::table('movimiento_detalles')
            ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
            ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
            ->whereIn('movimientos.categoria_movimiento', ['abastecimiento', 'devolucion', 'cajilla_apertura', 'cajilla_cierre', 'cierre_jornada_barrido', 'deteriorado', 'traslado_boveda', 'bancos_extraccion', 'bancos_inyeccion'])
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
            
            // Si la Bóveda de la sucursal ya cerró hoy, marcamos tanto la Bóveda como la Caja General como cerradas
            if ($bovedaCerradaHoy && in_array($caja->tipo_caja, ['boveda', 'general'])) {
                $cajasCerradasHoy[$caja->id] = true;
            }

            // Validar si ya existe un cierre consolidado hoy para esta caja específica
            $cierreHoy = \App\Models\CierreDiario::where('caja_id', $caja->id)
                ->where('fecha_cierre', $start->toDateString())
                ->with('detalles')
                ->first();

            $ultimoCierre = null;
            if ($cierreHoy) {
                $ultimoCierre = $cierreHoy;
                $cajasCerradasHoy[$caja->id] = true; // Por seguridad
            } elseif ($caja->tipo_caja === 'boveda') {
                // Si no hay cierre hoy, buscamos el cierre de la jornada anterior
                $ultimoCierre = \App\Models\CierreDiario::where('caja_id', $caja->id)
                    ->where('created_at', '<', $start)
                    ->with('detalles')
                    ->orderBy('id', 'desc')
                    ->first();
            }

            foreach ($denominaciones as $denom) {
                $cantInicialBueno = 0;
                $cantInicialCajillas = 0;
                $cantInicialDeteriorado = 0;
                
                if ($ultimoCierre) {
                    $cantInicialBueno = $ultimoCierre->detalles
                        ->where('denominacion_id', $denom->id)
                        ->where('estado_dinero', 'bueno')
                        ->sum('cantidad');
                    $cantInicialCajillas = $ultimoCierre->detalles
                        ->where('denominacion_id', $denom->id)
                        ->where('estado_dinero', 'cajillas')
                        ->sum('cantidad');
                    $cantInicialDeteriorado = $ultimoCierre->detalles
                        ->where('denominacion_id', $denom->id)
                        ->where('estado_dinero', 'deteriorado')
                        ->sum('cantidad');
                }

                $matriz[$caja->id][$denom->id] = [
                    // Operaciones (Bueno)
                    'saldo_inicial_cantidad' => (int)$cantInicialBueno,
                    'ingresos_cantidad' => 0,
                    'ingresos_monto' => 0.00,
                    'egresos_cantidad' => 0,
                    'egresos_monto' => 0.00,

                    // Cajillas
                    'cajillas_inicial_cantidad' => (int)$cantInicialCajillas,
                    'cajillas_ingresos_cantidad' => 0,
                    'cajillas_egresos_cantidad' => 0,

                    // Deteriorado
                    'deteriorado_inicial_cantidad' => (int)$cantInicialDeteriorado,
                    'deteriorado_ingreso_cantidad' => 0,
                    'deteriorado_egreso_cantidad' => 0,
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
            $monto = (float) $item->total_subtotal;
            $estado = $item->estado_dinero;
            $categoria = $item->categoria_movimiento;

            // A. Compartimento de Cajillas (En Tránsito)
            if (in_array($categoria, ['cajilla_apertura', 'cajilla_cierre'])) {
                // Apertura: Egreso para Bóveda y Egreso para Ventanilla
                if ($categoria === 'cajilla_apertura') {
                    if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                        $matriz[$item->origen_caja_id][$denomId]['cajillas_egresos_cantidad'] += $cant;
                    }
                    if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                        $matriz[$item->destino_caja_id][$denomId]['cajillas_egresos_cantidad'] += $cant;
                    }
                }
                // Cierre: Ingreso para Bóveda e Ingreso para Ventanilla
                if ($categoria === 'cajilla_cierre') {
                    if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                        $matriz[$item->destino_caja_id][$denomId]['cajillas_ingresos_cantidad'] += $cant;
                    }
                    if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                        $matriz[$item->origen_caja_id][$denomId]['cajillas_ingresos_cantidad'] += $cant;
                    }
                }
            }

            // B. Compartimento de Deteriorado
            if ($estado === 'deteriorado' || $categoria === 'deteriorado') {
                if ($categoria === 'bancos_extraccion') {
                    // Egreso de deteriorado por remesa a bancos
                    if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                        $matriz[$item->origen_caja_id][$denomId]['deteriorado_egreso_cantidad'] += $cant;
                    }
                    if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                        $matriz[$item->destino_caja_id][$denomId]['deteriorado_egreso_cantidad'] += $cant;
                    }
                } else {
                    // El traspaso de deteriorado a Bóveda se registra como INGRESO para ambas cajas
                    if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                        $matriz[$item->destino_caja_id][$denomId]['deteriorado_ingreso_cantidad'] += $cant;
                    }
                    if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                        $matriz[$item->origen_caja_id][$denomId]['deteriorado_ingreso_cantidad'] += $cant;
                    }
                }
            }

            // C. Compartimento Operativo de Dinero Bueno (Excluye aperturas, cierres y deteriorados)
            if ($estado === 'bueno') {
                if (in_array($categoria, ['traslado_boveda', 'bancos_extraccion', 'bancos_inyeccion'])) {
                    $tipoMov = $item->tipo_operacion;
                    if ($tipoMov === 'ingreso') {
                        if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                            $matriz[$item->destino_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                            $matriz[$item->destino_caja_id][$denomId]['ingresos_monto'] += $monto;
                        }
                        if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                            $matriz[$item->origen_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                            $matriz[$item->origen_caja_id][$denomId]['ingresos_monto'] += $monto;
                        }
                    }
                    if ($tipoMov === 'egreso') {
                        if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                            $matriz[$item->origen_caja_id][$denomId]['egresos_cantidad'] += $cant;
                            $matriz[$item->origen_caja_id][$denomId]['egresos_monto'] += $monto;
                        }
                        if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                            $matriz[$item->destino_caja_id][$denomId]['egresos_cantidad'] += $cant;
                            $matriz[$item->destino_caja_id][$denomId]['egresos_monto'] += $monto;
                        }
                    }
                } elseif ($categoria === 'abastecimiento') {
                    // Abastecimiento: Egreso para ambas cajas (Bóveda Origen y Ventanilla Destino)
                    if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                        $matriz[$item->origen_caja_id][$denomId]['egresos_cantidad'] += $cant;
                        $matriz[$item->origen_caja_id][$denomId]['egresos_monto'] += $monto;
                    }
                    if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                        $matriz[$item->destino_caja_id][$denomId]['egresos_cantidad'] += $cant;
                        $matriz[$item->destino_caja_id][$denomId]['egresos_monto'] += $monto;
                    }
                } elseif ($categoria === 'devolucion') {
                    // Devolución: Ingreso para ambas cajas (Ventanilla Origen y Bóveda Destino)
                    if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                        $matriz[$item->destino_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                        $matriz[$item->destino_caja_id][$denomId]['ingresos_monto'] += $monto;
                    }
                    if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                        $matriz[$item->origen_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                        $matriz[$item->origen_caja_id][$denomId]['ingresos_monto'] += $monto;
                    }
                } else {
                    // Cruce físico regular para las demás categorías
                    if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                        $matriz[$item->destino_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                        $matriz[$item->destino_caja_id][$denomId]['ingresos_monto'] += $monto;
                    }
                    if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                        $matriz[$item->origen_caja_id][$denomId]['egresos_cantidad'] += $cant;
                        $matriz[$item->origen_caja_id][$denomId]['egresos_monto'] += $monto;
                    }
                }
            }
        }

        // 4. Calcular el flujo de Cajillas (Aperturas y Cierres únicamente)
        $movimientosCajillas = DB::table('movimientos')
            ->whereBetween('fecha_transaccion', [$start, $end])
            ->whereIn('categoria_movimiento', ['cajilla_apertura', 'cajilla_cierre'])
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
                        ->where('created_at', '<', $start)
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

            // Procesar origen
            if ($item->origen_caja_id && isset($totalesCajillas[$item->origen_caja_id])) {
                if ($categoria === 'cajilla_apertura') {
                    $totalesCajillas[$item->origen_caja_id]['egresos'] += $total;
                }
                if ($categoria === 'cajilla_cierre') {
                    $totalesCajillas[$item->origen_caja_id]['ingresos'] += $total;
                }
            }

            // Procesar destino
            if ($item->destino_caja_id && isset($totalesCajillas[$item->destino_caja_id])) {
                if ($categoria === 'cajilla_apertura') {
                    $totalesCajillas[$item->destino_caja_id]['egresos'] += $total;
                }
                if ($categoria === 'cajilla_cierre') {
                    $totalesCajillas[$item->destino_caja_id]['ingresos'] += $total;
                }
            }
        }

        // 5. Calcular el flujo de Deteriorados (Movimientos con piezas marcadas como deterioradas)
        $movimientosDeteriorados = DB::table('movimiento_detalles')
            ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
            ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
            ->where('movimiento_detalles.estado_dinero', 'deteriorado')
            ->whereIn('movimientos.categoria_movimiento', ['deteriorado', 'bancos_extraccion'])
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
                        ->where('created_at', '<', $start)
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
                // Extracción a bancos: Egreso para ambas cajas involucradas
                if ($item->origen_caja_id && isset($totalesDeteriorados[$item->origen_caja_id])) {
                    $totalesDeteriorados[$item->origen_caja_id]['egresos'] += $total;
                }
                if ($item->destino_caja_id && isset($totalesDeteriorados[$item->destino_caja_id])) {
                    $totalesDeteriorados[$item->destino_caja_id]['egresos'] += $total;
                }
            } else {
                // Traspaso ordinario de deteriorado
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

        $boveda = $cajas->firstWhere('tipo_caja', 'boveda');
        $bovedaCerradaHoy = false;
        if ($boveda) {
            $bovedaCerradaHoy = DB::table('cierres_diarios')
                ->where('caja_id', $boveda->id)
                ->whereDate('fecha_cierre', Carbon::today())
                ->exists();
        }

        $agencias = \App\Models\Agencia::orderBy('nombre')->get();

        return response()->json([
            'fecha' => Carbon::today()->toDateString(),
            'cajas' => $cajas,
            'denominaciones' => $denominaciones,
            'matriz' => $matriz,
            'totales_cajillas' => $totalesCajillas,
            'totales_deteriorados' => $totalesDeteriorados,
            'boveda_cerrada_hoy' => $bovedaCerradaHoy,
            'agencias' => $agencias,
            'agencia_seleccionada_id' => (int) $agenciaId
        ]);
    }

    public function obtenerInventarioDeteriorado(Request $request, $cajaId)
    {
        $denominaciones = \App\Models\Denominacion::where('activo', true)
            ->orderBy('valor', 'desc')
            ->get();

        $inventario = [];

        // Obtener último cierre de Bóveda para tomar las cantidades iniciales de deteriorados
        $ultimoCierre = DB::table('cierres_diarios')
            ->where('caja_id', $cajaId)
            ->orderBy('id', 'desc')
            ->first();

        foreach ($denominaciones as $denom) {
            $cantidadInicial = 0;
            if ($ultimoCierre) {
                $cantidadInicial = DB::table('cierre_diario_detalles')
                    ->where('cierre_diario_id', $ultimoCierre->id)
                    ->where('denominacion_id', $denom->id)
                    ->where('estado_dinero', 'deteriorado')
                    ->value('cantidad') ?? 0;
            }

            // Ingresos (cuando destino_caja_id es la caja y no es carga inicial)
            $ingresos = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.destino_caja_id', $cajaId)
                ->where('movimiento_detalles.denominacion_id', $denom->id)
                ->where('movimiento_detalles.estado_dinero', 'deteriorado')
                ->where('movimientos.categoria_movimiento', '!=', 'carga_inicial_dia_cero')
                ->sum('movimiento_detalles.cantidad');

            // Egresos (cuando origen_caja_id es la caja y no es carga inicial)
            $egresos = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.origen_caja_id', $cajaId)
                ->where('movimiento_detalles.denominacion_id', $denom->id)
                ->where('movimiento_detalles.estado_dinero', 'deteriorado')
                ->where('movimientos.categoria_movimiento', '!=', 'carga_inicial_dia_cero')
                ->sum('movimiento_detalles.cantidad');

            $cantidadDisponible = (int) ($cantidadInicial + $ingresos - $egresos);

            if ($cantidadDisponible > 0) {
                $inventario[] = [
                    'id' => $denom->id,
                    'nombre' => $denom->nombre,
                    'valor' => (float) $denom->valor,
                    'tipo' => $denom->tipo,
                    'cantidad' => $cantidadDisponible,
                    'subtotal' => $denom->valor * $cantidadDisponible
                ];
            }
        }

        return response()->json($inventario);
    }

    public function obtenerInventarioCajillas(Request $request, $cajaId)
    {
        $denominaciones = \App\Models\Denominacion::where('activo', true)
            ->orderBy('valor', 'desc')
            ->get();

        $inventario = [];
        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();

        // Obtener último cierre de Bóveda para tomar las cantidades iniciales de cajillas
        $ultimoCierre = DB::table('cierres_diarios')
            ->where('caja_id', $cajaId)
            ->orderBy('id', 'desc')
            ->first();

        foreach ($denominaciones as $denom) {
            $cantidadInicial = 0;
            if ($ultimoCierre) {
                $cantidadInicial = DB::table('cierre_diario_detalles')
                    ->where('cierre_diario_id', $ultimoCierre->id)
                    ->where('denominacion_id', $denom->id)
                    ->where('estado_dinero', 'cajillas')
                    ->value('cantidad') ?? 0;
            }

            // Ingresos (cuando destino_caja_id es la Bóveda y es flujo de cajillas)
            $ingresos = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.destino_caja_id', $cajaId)
                ->where('movimiento_detalles.denominacion_id', $denom->id)
                ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                ->whereIn('movimientos.categoria_movimiento', ['cajilla_cierre', 'devolucion', 'cierre_jornada_barrido'])
                ->sum('movimiento_detalles.cantidad');

            // Egresos (cuando origen_caja_id es la Bóveda y es flujo de cajillas)
            $egresos = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.origen_caja_id', $cajaId)
                ->where('movimiento_detalles.denominacion_id', $denom->id)
                ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                ->whereIn('movimientos.categoria_movimiento', ['cajilla_apertura', 'abastecimiento'])
                ->sum('movimiento_detalles.cantidad');

            $cantidadDisponible = (int) ($cantidadInicial + $ingresos - $egresos);

            if ($cantidadDisponible > 0) {
                $inventario[] = [
                    'id' => $denom->id,
                    'nombre' => $denom->nombre,
                    'valor' => (float) $denom->valor,
                    'tipo' => $denom->tipo,
                    'cantidad' => $cantidadDisponible,
                    'subtotal' => $denom->valor * $cantidadDisponible
                ];
            }
        }

        return response()->json($inventario);
    }
}
