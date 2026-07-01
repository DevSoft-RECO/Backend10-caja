<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Denominacion;
use App\Models\Movimiento;
use App\Models\MovimientoDetalle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BancosOperacionController extends Controller
{
    /**
     * Procesa inyecciones y extracciones de efectivo con bancos externos.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'boveda_id' => 'required|exists:cajas,id',
            'tipo_operacion' => 'required|in:ingreso,egreso', // ingreso = inyectar, egreso = extraer/remesar
            'detalles' => 'required|array|min:1',
            'detalles.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles.*.cantidad' => 'required|integer|min:1',
            'detalles.*.estado_dinero' => 'required|in:bueno,deteriorado',
        ]);

        $boveda = Caja::with('agencia')->findOrFail($validated['boveda_id']);

        // 1. Validar que la caja sea una Bóveda
        if ($boveda->tipo_caja !== 'boveda') {
            return response()->json([
                'message' => 'Esta operación con bancos externos solo se permite realizar sobre una Bóveda.'
            ], 422);
        }

        // 2. Buscar la Caja General de la misma agencia
        $cajaGeneral = Caja::where('tipo_caja', 'general')
            ->where('agencia_id', $boveda->agencia_id)
            ->where('estado', true)
            ->first();

        if (!$cajaGeneral) {
            return response()->json([
                'message' => 'No se encontró una Caja General activa en la misma agencia para actuar como puente.'
            ], 422);
        }

        $tipoOp = $validated['tipo_operacion'];
        $detallesProcesados = [];
        $montoTotal = 0;

        // 3. Flujo de Validación de Saldos
        if ($tipoOp === 'egreso') {
            // Extracción / Remesa: Validar disponibilidad de la Bóveda (Bueno y Deteriorado)
            $start = Carbon::today()->startOfDay();
            $end = Carbon::today()->endOfDay();

            // Obtener cantidades iniciales del último cierre
            $ultimoCierre = DB::table('cierres_diarios')
                ->where('caja_id', $boveda->id)
                ->orderBy('id', 'desc')
                ->first();

            $cantidadesInicialesBueno = [];
            $cantidadesInicialesDeteriorado = [];

            if ($ultimoCierre) {
                $cantidadesInicialesBueno = DB::table('cierre_diario_detalles')
                    ->where('cierre_diario_id', $ultimoCierre->id)
                    ->where('estado_dinero', 'bueno')
                    ->pluck('cantidad', 'denominacion_id')
                    ->toArray();

                $cantidadesInicialesDeteriorado = DB::table('cierre_diario_detalles')
                    ->where('cierre_diario_id', $ultimoCierre->id)
                    ->where('estado_dinero', 'deteriorado')
                    ->pluck('cantidad', 'denominacion_id')
                    ->toArray();
            }

            // Obtener agregaciones de hoy
            $ingresosHoy = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.destino_caja_id', $boveda->id)
                ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                ->groupBy('movimiento_detalles.denominacion_id', 'movimiento_detalles.estado_dinero')
                ->select(
                    'movimiento_detalles.denominacion_id', 
                    'movimiento_detalles.estado_dinero',
                    DB::raw('SUM(movimiento_detalles.cantidad) as total')
                )
                ->get();

            $egresosHoy = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.origen_caja_id', $boveda->id)
                ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                ->groupBy('movimiento_detalles.denominacion_id', 'movimiento_detalles.estado_dinero')
                ->select(
                    'movimiento_detalles.denominacion_id', 
                    'movimiento_detalles.estado_dinero',
                    DB::raw('SUM(movimiento_detalles.cantidad) as total')
                )
                ->get();

            $denominaciones = Denominacion::whereIn('id', array_column($validated['detalles'], 'denominacion_id'))->get();

            foreach ($validated['detalles'] as $det) {
                $denom = $denominaciones->firstWhere('id', $det['denominacion_id']);
                $cantRequerida = (int) $det['cantidad'];
                $estado = $det['estado_dinero'];
                $denomId = $denom->id;

                // Calcular stock
                if ($estado === 'bueno') {
                    $cantInicial = (int) ($cantidadesInicialesBueno[$denomId] ?? 0);
                    $ingresos = (int) ($ingresosHoy->first(fn($x) => $x->denominacion_id === $denomId && $x->estado_dinero === 'bueno')?->total ?? 0);
                    $egresos = (int) ($egresosHoy->first(fn($x) => $x->denominacion_id === $denomId && $x->estado_dinero === 'bueno')?->total ?? 0);
                } else {
                    $cantInicial = (int) ($cantidadesInicialesDeteriorado[$denomId] ?? 0);
                    $ingresos = (int) ($ingresosHoy->first(fn($x) => $x->denominacion_id === $denomId && $x->estado_dinero === 'deteriorado')?->total ?? 0);
                    $egresos = (int) ($egresosHoy->first(fn($x) => $x->denominacion_id === $denomId && $x->estado_dinero === 'deteriorado')?->total ?? 0);
                }

                $disponible = $cantInicial + $ingresos - $egresos;

                if ($disponible < $cantRequerida) {
                    $estadoLabel = $estado === 'bueno' ? 'bueno' : 'deteriorado';
                    return response()->json([
                        'message' => "Saldo insuficiente para la denominación {$denom->nombre} ({$estadoLabel}). Requerido: {$cantRequerida}, Disponible: {$disponible}."
                    ], 422);
                }

                $subtotal = $denom->valor * $cantRequerida;
                $montoTotal += $subtotal;

                $detallesProcesados[] = [
                    'denominacion_id' => $denomId,
                    'cantidad' => $cantRequerida,
                    'subtotal' => $subtotal,
                    'estado_dinero' => $estado
                ];
            }
        } else {
            // Inyección / Carga: Solo permite Dinero Bueno
            $denominaciones = Denominacion::whereIn('id', array_column($validated['detalles'], 'denominacion_id'))->get();

            foreach ($validated['detalles'] as $det) {
                if ($det['estado_dinero'] !== 'bueno') {
                    return response()->json([
                        'message' => 'No se permite la inyección de efectivo deteriorado desde bancos externos.'
                    ], 422);
                }

                $denom = $denominaciones->firstWhere('id', $det['denominacion_id']);
                $cant = (int) $det['cantidad'];
                $subtotal = $denom->valor * $cant;
                $montoTotal += $subtotal;

                $detallesProcesados[] = [
                    'denominacion_id' => $denom->id,
                    'cantidad' => $cant,
                    'subtotal' => $subtotal,
                    'estado_dinero' => 'bueno'
                ];
            }
        }

        // 4. Ejecutar el movimiento
        DB::beginTransaction();
        try {
            if ($tipoOp === 'egreso') {
                // EXTRACCIÓN A BANCOS: Egreso de Bóveda hacia Caja General
                $movimiento = Movimiento::create([
                    'origen_caja_id' => $boveda->id,
                    'destino_caja_id' => $cajaGeneral->id,
                    'tipo_operacion' => 'egreso',
                    'categoria_movimiento' => 'bancos_extraccion',
                    'monto_total' => $montoTotal,
                    'usuario_id' => auth()->id() ?? User::first()->id,
                    'fecha_transaccion' => Carbon::now(),
                    'comentario' => "Remesa / Extracción de efectivo hacia banco externo."
                ]);
            } else {
                // INYECCIÓN DESDE BANCOS: Ingreso de Caja General hacia Bóveda
                $movimiento = Movimiento::create([
                    'origen_caja_id' => $cajaGeneral->id,
                    'destino_caja_id' => $boveda->id,
                    'tipo_operacion' => 'ingreso',
                    'categoria_movimiento' => 'bancos_inyeccion',
                    'monto_total' => $montoTotal,
                    'usuario_id' => auth()->id() ?? User::first()->id,
                    'fecha_transaccion' => Carbon::now(),
                    'comentario' => "Inyección / Fondeo de efectivo desde banco externo."
                ]);
            }

            foreach ($detallesProcesados as $det) {
                MovimientoDetalle::create(array_merge($det, [
                    'movimiento_id' => $movimiento->id
                ]));
            }

            DB::commit();

            return response()->json([
                'message' => 'Operación con bancos externos registrada exitosamente.',
                'movimiento_id' => $movimiento->id,
                'monto_total' => $montoTotal
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al procesar la operación bancaria en la base de datos: ' . $e->getMessage()
            ], 500);
        }
    }
}
