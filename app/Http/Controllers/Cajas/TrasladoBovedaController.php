<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Denominacion;
use App\Models\Movimiento;
use App\Models\MovimientoDetalle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TrasladoBovedaController extends Controller
{
    /**
     * Registra un traslado de efectivo entre dos bóvedas a través de la Caja General.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'boveda_origen_id' => 'required|exists:cajas,id',
            'boveda_destino_id' => 'required|exists:cajas,id|different:boveda_origen_id',
            'detalles' => 'required|array|min:1',
            'detalles.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles.*.cantidad' => 'required|integer|min:1',
        ]);

        $bovedaOrigen = Caja::findOrFail($validated['boveda_origen_id']);
        $bovedaDestino = Caja::findOrFail($validated['boveda_destino_id']);

        // 1. Validar que ambas cajas sean efectivamente Bóvedas
        if ($bovedaOrigen->tipo_caja !== 'boveda' || $bovedaDestino->tipo_caja !== 'boveda') {
            return response()->json([
                'message' => 'El traslado entre bóvedas solo se permite entre cajas de tipo boveda.'
            ], 422);
        }

        // 2. Buscar las Cajas Generales correspondientes
        $cajaGeneralOrigen = Caja::where('tipo_caja', 'general')
            ->where('agencia_id', $bovedaOrigen->agencia_id)
            ->where('estado', true)
            ->first();

        $cajaGeneralDestino = Caja::where('tipo_caja', 'general')
            ->where('agencia_id', $bovedaDestino->agencia_id)
            ->where('estado', true)
            ->first();

        if (!$cajaGeneralOrigen) {
            return response()->json([
                'message' => 'No se encontró una Caja General activa en la agencia de origen para procesar el traslado.'
            ], 422);
        }

        if (!$cajaGeneralDestino) {
            return response()->json([
                'message' => 'No se encontró una Caja General activa en la agencia de destino para procesar el traslado.'
            ], 422);
        }

        // 3. Validar disponibilidad de saldo en la Bóveda Origen para cada denominación (Dinero Bueno)
        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();

        // Obtener cantidades iniciales del último cierre
        $ultimoCierre = DB::table('cierres_diarios')
            ->where('caja_id', $bovedaOrigen->id)
            ->orderBy('id', 'desc')
            ->first();

        $cantidadesIniciales = [];
        if ($ultimoCierre) {
            $cantidadesIniciales = DB::table('cierre_diario_detalles')
                ->where('cierre_diario_id', $ultimoCierre->id)
                ->where('estado_dinero', 'bueno')
                ->pluck('cantidad', 'denominacion_id')
                ->toArray();
        }

        // Obtener ingresos y egresos de hoy para Dinero Bueno
        $ingresosHoy = DB::table('movimiento_detalles')
            ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
            ->where('movimientos.destino_caja_id', $bovedaOrigen->id)
            ->where('movimiento_detalles.estado_dinero', 'bueno')
            ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
            ->groupBy('movimiento_detalles.denominacion_id')
            ->select('movimiento_detalles.denominacion_id', DB::raw('SUM(movimiento_detalles.cantidad) as total'))
            ->pluck('total', 'denominacion_id')
            ->toArray();

        $egresosHoy = DB::table('movimiento_detalles')
            ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
            ->where('movimientos.origen_caja_id', $bovedaOrigen->id)
            ->where('movimiento_detalles.estado_dinero', 'bueno')
            ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
            ->groupBy('movimiento_detalles.denominacion_id')
            ->select('movimiento_detalles.denominacion_id', DB::raw('SUM(movimiento_detalles.cantidad) as total'))
            ->pluck('total', 'denominacion_id')
            ->toArray();

        // Validar stock denominación por denominación
        $denominaciones = Denominacion::whereIn('id', array_column($validated['detalles'], 'denominacion_id'))->get();
        $detallesProcesados = [];
        $montoTotal = 0;

        foreach ($validated['detalles'] as $det) {
            $denom = $denominaciones->firstWhere('id', $det['denominacion_id']);
            $cantRequerida = (int) $det['cantidad'];
            $denomId = $denom->id;

            $cantInicial = (int) ($cantidadesIniciales[$denomId] ?? 0);
            $ingresos = (int) ($ingresosHoy[$denomId] ?? 0);
            $egresos = (int) ($egresosHoy[$denomId] ?? 0);

            $disponible = $cantInicial + $ingresos - $egresos;

            if ($disponible < $cantRequerida) {
                return response()->json([
                    'message' => "Saldo insuficiente para la denominación {$denom->nombre}. Requerido: {$cantRequerida}, Disponible: {$disponible}."
                ], 422);
            }

            $subtotal = $denom->valor * $cantRequerida;
            $montoTotal += $subtotal;

            $detallesProcesados[] = [
                'denominacion_id' => $denomId,
                'cantidad' => $cantRequerida,
                'subtotal' => $subtotal,
                'estado_dinero' => 'bueno'
            ];
        }

        // 4. Ejecutar el traslado bajo una transacción SQL
        DB::beginTransaction();
        try {
            // A. MOVIMIENTO 1: Egreso de Bóveda Origen a Caja General de Origen
            $movimientoEgreso = Movimiento::create([
                'origen_caja_id' => $bovedaOrigen->id,
                'destino_caja_id' => $cajaGeneralOrigen->id,
                'tipo_operacion' => 'egreso',
                'categoria_movimiento' => 'traslado_boveda',
                'monto_total' => $montoTotal,
                'usuario_id' => auth()->id() ?? User::first()->id, // Fallback por si corre en pruebas
                'fecha_transaccion' => Carbon::now(),
                'comentario' => "Traslado de efectivo (salida) hacia Bóveda de Agencia {$bovedaDestino->agencia->nombre}."
            ]);

            foreach ($detallesProcesados as $det) {
                MovimientoDetalle::create(array_merge($det, [
                    'movimiento_id' => $movimientoEgreso->id
                ]));
            }

            // B. MOVIMIENTO 2: Ingreso de Caja General de Destino a Bóveda Destino
            $movimientoIngreso = Movimiento::create([
                'origen_caja_id' => $cajaGeneralDestino->id,
                'destino_caja_id' => $bovedaDestino->id,
                'tipo_operacion' => 'ingreso',
                'categoria_movimiento' => 'traslado_boveda',
                'monto_total' => $montoTotal,
                'usuario_id' => auth()->id() ?? User::first()->id,
                'fecha_transaccion' => Carbon::now(),
                'comentario' => "Traslado de efectivo (entrada) desde Bóveda de Agencia {$bovedaOrigen->agencia->nombre}."
            ]);

            foreach ($detallesProcesados as $det) {
                MovimientoDetalle::create(array_merge($det, [
                    'movimiento_id' => $movimientoIngreso->id
                ]));
            }

            DB::commit();

            return response()->json([
                'message' => 'Traslado entre Bóvedas ejecutado y registrado exitosamente.',
                'egreso_movimiento_id' => $movimientoEgreso->id,
                'ingreso_movimiento_id' => $movimientoIngreso->id,
                'monto_total' => $montoTotal
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al procesar el traslado en base de datos: ' . $e->getMessage()
            ], 500);
        }
    }
}
