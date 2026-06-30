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

        // 1. Obtener todas las cajas y denominaciones activas
        $cajas = Caja::with('agencia')->where('estado', true)->get();
        $denominaciones = Denominacion::where('activo', true)->orderBy('valor', 'desc')->get();

        // 2. Query de agregación agrupada para movimientos de hoy (Flujo regular)
        $movimientosHoy = DB::table('movimiento_detalles')
            ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
            ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
            ->whereIn('movimientos.categoria_movimiento', ['abastecimiento', 'devolucion', 'cajilla_apertura', 'cajilla_cierre'])
            ->select(
                'movimientos.origen_caja_id',
                'movimientos.destino_caja_id',
                'movimientos.categoria_movimiento',
                'movimiento_detalles.denominacion_id',
                'movimiento_detalles.estado_dinero',
                DB::raw('SUM(movimiento_detalles.cantidad) as total_cantidad'),
                DB::raw('SUM(movimiento_detalles.subtotal) as total_subtotal')
            )
            ->groupBy(
                'movimientos.origen_caja_id',
                'movimientos.destino_caja_id',
                'movimientos.categoria_movimiento',
                'movimiento_detalles.denominacion_id',
                'movimiento_detalles.estado_dinero'
            )
            ->get();

        // 3. Estructurar matriz: [caja_id][denominacion_id] = { saldo_inicial_cantidad, ingresos: 0, egresos: 0, deteriorados_recibidos: 0 }
        $matriz = [];
        
        foreach ($cajas as $caja) {
            $matriz[$caja->id] = [];
            
            $ultimoCierre = null;
            if ($caja->tipo_caja === 'boveda') {
                $ultimoCierre = \App\Models\CierreDiario::where('caja_id', $caja->id)
                    ->where('fecha_cierre', '<', Carbon::today())
                    ->with('detalles')
                    ->orderBy('id', 'desc')
                    ->first();
            }

            foreach ($denominaciones as $denom) {
                $cantInicial = 0;
                if ($ultimoCierre) {
                    $cantInicial = $ultimoCierre->detalles
                        ->where('denominacion_id', $denom->id)
                        ->where('estado_dinero', 'bueno')
                        ->sum('cantidad');
                }

                $matriz[$caja->id][$denom->id] = [
                    'saldo_inicial_cantidad' => (int)$cantInicial,
                    'ingresos_cantidad' => 0,
                    'ingresos_monto' => 0.00,
                    'egresos_cantidad' => 0,
                    'egresos_monto' => 0.00,
                    'deteriorado_ingreso_cantidad' => 0,
                    'deteriorado_egreso_cantidad' => 0,
                ];
            }
        }

        // Procesar los agregados
        foreach ($movimientosHoy as $item) {
            $denomId = $item->denominacion_id;
            $cant = (int) $item->total_cantidad;
            $monto = (float) $item->total_subtotal;
            $estado = $item->estado_dinero;
            $categoria = $item->categoria_movimiento;

            // Para la Bóveda, se mantiene la lógica contable real del Libro Mayor:
            // - Bóveda como origen: egreso (enviado).
            // - Bóveda como destino: ingreso (recibido).

            // Para las Ventanillas de Atención (Cajillas):
            // - Apertura ('cajilla_apertura') y Abastecimiento ('abastecimiento'): Representan egresos (efectivo enviado a gaveta que saldrá hacia los asociados).
            // - Cierre ('cajilla_cierre') y Devolución ('devolucion'): Representan ingresos (recibido de vuelta a boveda o devuelto).

            if ($estado === 'bueno') {
                if ($categoria === 'devolucion') {
                    if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                        $matriz[$item->origen_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                        $matriz[$item->origen_caja_id][$denomId]['ingresos_monto'] += $monto;
                    }
                    if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                        $matriz[$item->destino_caja_id][$denomId]['ingresos_cantidad'] += $cant;
                        $matriz[$item->destino_caja_id][$denomId]['ingresos_monto'] += $monto;
                    }
                }

                if ($categoria === 'abastecimiento') {
                    if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                        $matriz[$item->origen_caja_id][$denomId]['egresos_cantidad'] += $cant;
                        $matriz[$item->origen_caja_id][$denomId]['egresos_monto'] += $monto;
                    }
                    if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                        $matriz[$item->destino_caja_id][$denomId]['egresos_cantidad'] += $cant;
                        $matriz[$item->destino_caja_id][$denomId]['egresos_monto'] += $monto;
                    }
                }
            }

            if ($estado === 'deteriorado') {
                if ($item->origen_caja_id && isset($matriz[$item->origen_caja_id][$denomId])) {
                    $matriz[$item->origen_caja_id][$denomId]['deteriorado_egreso_cantidad'] += $cant;
                }
                if ($item->destino_caja_id && isset($matriz[$item->destino_caja_id][$denomId])) {
                    $matriz[$item->destino_caja_id][$denomId]['deteriorado_ingreso_cantidad'] += $cant;
                }
            }
        }

        // 4. Calcular el flujo de Cajillas (Aperturas, Cierres y Barridos)
        $movimientosCajillas = DB::table('movimientos')
            ->whereBetween('fecha_transaccion', [$start, $end])
            ->whereIn('categoria_movimiento', ['cajilla_apertura', 'cajilla_cierre', 'cierre_jornada_barrido', 'abastecimiento', 'devolucion'])
            ->select('origen_caja_id', 'destino_caja_id', 'categoria_movimiento', DB::raw('SUM(monto_total) as total'))
            ->groupBy('origen_caja_id', 'destino_caja_id', 'categoria_movimiento')
            ->get();

        $totalesCajillas = [];
        foreach ($cajas as $caja) {
            $saldoInicialCajillas = 0.00;
            if ($caja->tipo_caja === 'boveda') {
                $ultimoCierreBoveda = \App\Models\CierreDiario::where('caja_id', $caja->id)
                    ->where('fecha_cierre', '<', Carbon::today())
                    ->orderBy('id', 'desc')
                    ->first();
                if ($ultimoCierreBoveda) {
                    $saldoInicialCajillas = (float) $ultimoCierreBoveda->saldo_final_cajillas;
                }
            }

            $totalesCajillas[$caja->id] = [
                'saldo_inicial' => (float)$saldoInicialCajillas,
                'ingresos' => 0.00,
                'egresos' => 0.00
            ];
        }
        foreach ($movimientosCajillas as $item) {
            $total = (float) $item->total;
            $categoria = $item->categoria_movimiento;

            // Procesar origen
            if ($item->origen_caja_id && isset($totalesCajillas[$item->origen_caja_id])) {
                $caja = $cajas->firstWhere('id', $item->origen_caja_id);
                if ($caja && $caja->tipo_caja === 'ventanilla') {
                    if ($categoria === 'cajilla_cierre') {
                        $totalesCajillas[$item->origen_caja_id]['ingresos'] += $total;
                    }
                } else {
                    // Para Bóveda u otros
                    if ($categoria === 'cajilla_apertura') {
                        $totalesCajillas[$item->origen_caja_id]['egresos'] += $total;
                    }
                }
            }

            // Procesar destino
            if ($item->destino_caja_id && isset($totalesCajillas[$item->destino_caja_id])) {
                $caja = $cajas->firstWhere('id', $item->destino_caja_id);
                if ($caja && $caja->tipo_caja === 'ventanilla') {
                    if ($categoria === 'cajilla_apertura') {
                        $totalesCajillas[$item->destino_caja_id]['egresos'] += $total;
                    }
                } else {
                    // Para Bóveda u otros
                    if ($categoria === 'cajilla_cierre') {
                        $totalesCajillas[$item->destino_caja_id]['ingresos'] += $total;
                    }
                }
            }
        }

        // 5. Calcular el flujo de Deteriorados (Movimientos con piezas marcadas como deterioradas de la categoría deteriorado)
        $movimientosDeteriorados = DB::table('movimiento_detalles')
            ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
            ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
            ->where('movimiento_detalles.estado_dinero', 'deteriorado')
            ->where('movimientos.categoria_movimiento', 'deteriorado')
            ->select('movimientos.origen_caja_id', 'movimientos.destino_caja_id', DB::raw('SUM(movimiento_detalles.subtotal) as total'))
            ->groupBy('movimientos.origen_caja_id', 'movimientos.destino_caja_id')
            ->get();

        $totalesDeteriorados = [];
        foreach ($cajas as $caja) {
            $saldoInicialDeteriorados = 0.00;
            if ($caja->tipo_caja === 'boveda') {
                $ultimoCierreBoveda = \App\Models\CierreDiario::where('caja_id', $caja->id)
                    ->where('fecha_cierre', '<', Carbon::today())
                    ->orderBy('id', 'desc')
                    ->first();
                if ($ultimoCierreBoveda) {
                    $saldoInicialDeteriorados = (float) $ultimoCierreBoveda->saldo_final_deteriorado;
                }
            }

            $totalesDeteriorados[$caja->id] = [
                'saldo_inicial' => (float)$saldoInicialDeteriorados,
                'ingresos' => 0.00,
                'egresos' => 0.00
            ];
        }
        foreach ($movimientosDeteriorados as $item) {
            $total = (float) $item->total;
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

        $boveda = $cajas->firstWhere('tipo_caja', 'boveda');
        $bovedaCerradaHoy = false;
        if ($boveda) {
            $bovedaCerradaHoy = DB::table('cierres_diarios')
                ->where('caja_id', $boveda->id)
                ->whereDate('fecha_cierre', Carbon::today())
                ->exists();
        }

        return response()->json([
            'fecha' => Carbon::today()->toDateString(),
            'cajas' => $cajas,
            'denominaciones' => $denominaciones,
            'matriz' => $matriz,
            'totales_cajillas' => $totalesCajillas,
            'totales_deteriorados' => $totalesDeteriorados,
            'boveda_cerrada_hoy' => $bovedaCerradaHoy
        ]);
    }

    public function obtenerInventarioDeteriorado(Request $request, $cajaId)
    {
        $denominaciones = \App\Models\Denominacion::where('activo', true)
            ->orderBy('valor', 'desc')
            ->get();

        $inventario = [];

        foreach ($denominaciones as $denom) {
            // Ingresos (cuando destino_caja_id es la caja)
            $ingresos = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.destino_caja_id', $cajaId)
                ->where('movimiento_detalles.denominacion_id', $denom->id)
                ->where('movimiento_detalles.estado_dinero', 'deteriorado')
                ->sum('movimiento_detalles.cantidad');

            // Egresos (cuando origen_caja_id es la caja)
            $egresos = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.origen_caja_id', $cajaId)
                ->where('movimiento_detalles.denominacion_id', $denom->id)
                ->where('movimiento_detalles.estado_dinero', 'deteriorado')
                ->sum('movimiento_detalles.cantidad');

            $cantidadDisponible = (int) ($ingresos - $egresos);

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
