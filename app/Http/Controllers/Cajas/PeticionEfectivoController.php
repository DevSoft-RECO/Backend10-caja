<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Denominacion;
use App\Models\Movimiento;
use App\Models\MovimientoDetalle;
use App\Models\SolicitudTrasladoBoveda;
use App\Models\SolicitudTrasladoBovedaDetalle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PeticionEfectivoController extends Controller
{
    /**
     * Listar peticiones propias (las que solicitó la agencia)
     */
    public function indexPropias(Request $request)
    {
        $request->validate(['caja_id' => 'required|exists:cajas,id']);
        
        $traslados = SolicitudTrasladoBoveda::with([
            'origenBoveda.agencia',
            'destinoBoveda.agencia',
            'creador',
            'detalles.denominacion'
        ])
        ->where('tipo_traslado', 'pedir')
        ->where(function($query) use ($request) {
            $query->where('origen_boveda_id', $request->caja_id)
                  ->orWhere('destino_boveda_id', $request->caja_id);
        })
        ->orderBy('id', 'desc')
        ->get();

        return response()->json($traslados);
    }

    /**
     * Listar peticiones para Tesorería (sin asignar agencia proveedora)
     */
    public function indexParaTesoreria()
    {
        // Add gate or permission check here if necessary, though route middleware should handle it.
        $traslados = SolicitudTrasladoBoveda::with([
            'destinoBoveda.agencia',
            'creador',
            'detalles.denominacion'
        ])
        ->where('tipo_traslado', 'pedir')
        ->where('estado', 'pendiente_tesoreria')
        ->orderBy('id', 'desc')
        ->get();

        return response()->json($traslados);
    }

    /**
     * Listar peticiones asignadas a una bóveda para que las autorice y despache
     */
    public function indexParaAutorizar(Request $request)
    {
        $request->validate(['caja_id' => 'required|exists:cajas,id']);

        $traslados = SolicitudTrasladoBoveda::with([
            'destinoBoveda.agencia',
            'creador',
            'detalles.denominacion'
        ])
        ->where('tipo_traslado', 'pedir')
        ->where('origen_boveda_id', $request->caja_id)
        ->whereIn('estado', ['pendiente', 'programado', 'enviado', 'paquete_recibido', 'ingresado'])
        ->orderBy('id', 'desc')
        ->get();

        return response()->json($traslados);
    }

    /**
     * Crear una petición de efectivo (Agencia solicitante)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'boveda_destino_id' => 'required|exists:cajas,id', // Quien pide el dinero
            'comentario_peticion' => 'nullable|string',
            'detalles' => 'required|array|min:1',
            'detalles.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles.*.cantidad' => 'required|integer|min:1',
        ]);

        $bovedaDestino = Caja::findOrFail($validated['boveda_destino_id']);

        if ($bovedaDestino->tipo_caja !== 'boveda') {
            return response()->json(['message' => 'Solo se puede pedir dinero para una caja tipo bóveda.'], 422);
        }

        $montoTotal = 0;
        $detallesProcesados = [];
        $denominaciones = Denominacion::whereIn('id', array_column($validated['detalles'], 'denominacion_id'))->get();

        foreach ($validated['detalles'] as $det) {
            $denom = $denominaciones->firstWhere('id', $det['denominacion_id']);
            $cant = (int) $det['cantidad'];
            $subtotal = $denom->valor * $cant;
            $montoTotal += $subtotal;

            $detallesProcesados[] = [
                'denominacion_id' => $denom->id,
                'cantidad' => $cant,
                'subtotal' => $subtotal
            ];
        }

        DB::beginTransaction();
        try {
            $solicitud = SolicitudTrasladoBoveda::create([
                'origen_boveda_id' => null, // Tesorería asignará
                'destino_boveda_id' => $bovedaDestino->id,
                'tipo_traslado' => 'pedir',
                'monto_total' => $montoTotal,
                'comentario_peticion' => $validated['comentario_peticion'] ?? null,
                'usuario_creador_id' => auth()->id() ?? User::first()->id,
                'estado' => 'pendiente_tesoreria'
            ]);

            foreach ($detallesProcesados as $det) {
                SolicitudTrasladoBovedaDetalle::create(array_merge($det, [
                    'solicitud_traslado_id' => $solicitud->id
                ]));
            }

            DB::commit();

            return response()->json([
                'message' => 'Petición enviada a Tesorería exitosamente.',
                'solicitud' => $solicitud
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al crear la petición: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Tesorería asigna qué agencia (bóveda origen) proveerá el efectivo
     */
    public function asignarAgencia(Request $request, $id)
    {
        $validated = $request->validate([
            'boveda_origen_id' => 'required|exists:cajas,id'
        ]);

        $solicitud = SolicitudTrasladoBoveda::findOrFail($id);

        if ($solicitud->estado !== 'pendiente_tesoreria') {
            return response()->json(['message' => 'La solicitud no está pendiente de asignación.'], 422);
        }

        if ($solicitud->destino_boveda_id == $validated['boveda_origen_id']) {
            return response()->json(['message' => 'La bóveda origen no puede ser la misma que la de destino.'], 422);
        }

        $solicitud->update([
            'origen_boveda_id' => $validated['boveda_origen_id'],
            'estado' => 'pendiente' // Ahora la agencia proveedora lo verá y podrá despachar
        ]);

        return response()->json([
            'message' => 'Agencia asignada exitosamente.',
            'solicitud' => $solicitud
        ]);
    }

    /**
     * La agencia proveedora autoriza y despacha el efectivo
     */
    public function autorizarYDespachar(Request $request, $id)
    {
        $validated = $request->validate([
            'repartidor' => 'required|string|max:255',
            'fecha_programada' => 'required|date',
            'comentario_envio' => 'nullable|string'
        ]);

        $solicitud = SolicitudTrasladoBoveda::with('detalles')->findOrFail($id);

        if (!in_array($solicitud->estado, ['pendiente', 'programado'])) {
            return response()->json(['message' => 'La solicitud no está lista para enviarse.'], 422);
        }

        $bovedaOrigen = Caja::findOrFail($solicitud->origen_boveda_id);
        $bovedaDestino = Caja::findOrFail($solicitud->destino_boveda_id);

        $detallesProcesados = $solicitud->detalles->map(function($det) {
            return [
                'denominacion_id' => $det->denominacion_id,
                'cantidad' => $det->cantidad,
                'subtotal' => $det->subtotal
            ];
        })->toArray();

        DB::beginTransaction();
        try {
            $errorSaldo = $this->validarSaldoBoveda($bovedaOrigen, $detallesProcesados);
            if ($errorSaldo) {
                DB::rollBack();
                return response()->json(['message' => $errorSaldo], 422);
            }

            $cajaGeneralOrigen = Caja::where('tipo_caja', 'general')
                ->where('agencia_id', $bovedaOrigen->agencia_id)
                ->where('estado', true)
                ->first();

            if (!$cajaGeneralOrigen) {
                DB::rollBack();
                return response()->json(['message' => 'No se encontró una Caja General activa en la agencia de origen.'], 422);
            }

            // Registrar Egreso
            $movimientoEgreso = Movimiento::create([
                'origen_caja_id' => $bovedaOrigen->id,
                'destino_caja_id' => $cajaGeneralOrigen->id,
                'tipo_operacion' => 'egreso',
                'categoria_movimiento' => 'traslado_boveda',
                'monto_total' => $solicitud->monto_total,
                'usuario_id' => auth()->id() ?? User::first()->id,
                'fecha_transaccion' => Carbon::now(),
                'comentario' => "Traslado de efectivo (despacho por petición de tesorería) hacia Bóveda de Agencia {$bovedaDestino->agencia->nombre}. Repartidor: {$validated['repartidor']}"
            ]);

            foreach ($detallesProcesados as $det) {
                MovimientoDetalle::create([
                    'movimiento_id' => $movimientoEgreso->id,
                    'denominacion_id' => $det['denominacion_id'],
                    'cantidad' => $det['cantidad'],
                    'subtotal' => $det['subtotal'],
                    'estado_dinero' => 'bueno'
                ]);
            }

            $solicitud->update([
                'fecha_programada' => Carbon::parse($validated['fecha_programada']),
                'repartidor' => $validated['repartidor'],
                'comentario_envio' => $validated['comentario_envio'] ?? null,
                'estado' => 'enviado'
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Efectivo despachado exitosamente.',
                'solicitud' => $solicitud
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al registrar despacho: ' . $e->getMessage()], 500);
        }
    }

    /**
     * La agencia que pidió confirma la recepción del paquete
     */
    public function confirmarRecepcion($id)
    {
        $solicitud = SolicitudTrasladoBoveda::findOrFail($id);

        if ($solicitud->estado !== 'enviado') {
            return response()->json(['message' => 'La solicitud no está en estado enviado.'], 422);
        }

        $solicitud->update(['estado' => 'paquete_recibido']);

        return response()->json([
            'message' => 'Recepción del paquete confirmada.',
            'solicitud' => $solicitud
        ]);
    }

    /**
     * La agencia que pidió ingresa el efectivo a su bóveda
     */
    public function ingresarEfectivo($id)
    {
        $solicitud = SolicitudTrasladoBoveda::with('detalles')->findOrFail($id);

        if ($solicitud->estado !== 'paquete_recibido') {
            return response()->json(['message' => 'Primero debe confirmar la recepción del paquete.'], 422);
        }

        $bovedaOrigen = Caja::findOrFail($solicitud->origen_boveda_id);
        $bovedaDestino = Caja::findOrFail($solicitud->destino_boveda_id);

        $cajaGeneralDestino = Caja::where('tipo_caja', 'general')
            ->where('agencia_id', $bovedaDestino->agencia_id)
            ->where('estado', true)
            ->first();

        if (!$cajaGeneralDestino) {
            return response()->json(['message' => 'No se encontró una Caja General activa en la agencia de destino.'], 422);
        }

        DB::beginTransaction();
        try {
            // Registrar Movimiento de Ingreso
            $movimientoIngreso = Movimiento::create([
                'origen_caja_id' => $cajaGeneralDestino->id,
                'destino_caja_id' => $bovedaDestino->id,
                'tipo_operacion' => 'ingreso',
                'categoria_movimiento' => 'traslado_boveda',
                'monto_total' => $solicitud->monto_total,
                'usuario_id' => auth()->id() ?? User::first()->id,
                'fecha_transaccion' => Carbon::now(),
                'comentario' => "Confirmación de ingreso de petición desde Bóveda de Agencia {$bovedaOrigen->agencia->nombre}. Repartidor: {$solicitud->repartidor}"
            ]);

            foreach ($solicitud->detalles as $det) {
                MovimientoDetalle::create([
                    'movimiento_id' => $movimientoIngreso->id,
                    'denominacion_id' => $det->denominacion_id,
                    'cantidad' => $det->cantidad,
                    'subtotal' => $det->subtotal,
                    'estado_dinero' => 'bueno'
                ]);
            }

            $solicitud->update(['estado' => 'ingresado']);

            DB::commit();

            return response()->json([
                'message' => 'Ingreso de efectivo a bóveda registrado exitosamente.',
                'solicitud' => $solicitud
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al ingresar efectivo: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $solicitud = SolicitudTrasladoBoveda::with('detalles')->findOrFail($id);

        if (in_array($solicitud->estado, ['ingresado', 'cancelado'])) {
            return response()->json(['message' => 'No se puede cancelar una petición ingresada o cancelada.'], 422);
        }

        DB::beginTransaction();
        try {
            // Si ya fue despachado, reversar el egreso de la bóveda proveedora
            if (in_array($solicitud->estado, ['enviado', 'paquete_recibido'])) {
                $bovedaOrigen = Caja::findOrFail($solicitud->origen_boveda_id);
                $cajaGeneralOrigen = Caja::where('tipo_caja', 'general')
                    ->where('agencia_id', $bovedaOrigen->agencia_id)
                    ->where('estado', true)
                    ->first();

                if (!$cajaGeneralOrigen) {
                    DB::rollBack();
                    return response()->json(['message' => 'No se encontró una Caja General activa para la reversión.'], 422);
                }

                $movimientoIngreso = Movimiento::create([
                    'origen_caja_id' => $cajaGeneralOrigen->id,
                    'destino_caja_id' => $bovedaOrigen->id,
                    'tipo_operacion' => 'ingreso',
                    'categoria_movimiento' => 'traslado_boveda',
                    'monto_total' => $solicitud->monto_total,
                    'usuario_id' => auth()->id() ?? User::first()->id,
                    'fecha_transaccion' => Carbon::now(),
                    'comentario' => "Reversión por cancelación de despacho #{$solicitud->id}."
                ]);

                foreach ($solicitud->detalles as $det) {
                    MovimientoDetalle::create([
                        'movimiento_id' => $movimientoIngreso->id,
                        'denominacion_id' => $det->denominacion_id,
                        'cantidad' => $det->cantidad,
                        'subtotal' => $det->subtotal,
                        'estado_dinero' => 'bueno'
                    ]);
                }
            }

            $solicitud->update(['estado' => 'cancelado']);

            DB::commit();

            return response()->json([
                'message' => 'Petición cancelada correctamente.',
                'solicitud' => $solicitud
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al cancelar petición: ' . $e->getMessage()], 500);
        }
    }

    private function validarSaldoBoveda($boveda, $detalles)
    {
        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();

        $ultimoCierre = DB::table('cierres_diarios')
            ->where('caja_id', $boveda->id)
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

        $ingresosHoy = DB::table('movimiento_detalles')
            ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
            ->where('movimientos.destino_caja_id', $boveda->id)
            ->where('movimiento_detalles.estado_dinero', 'bueno')
            ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
            ->groupBy('movimiento_detalles.denominacion_id')
            ->select('movimiento_detalles.denominacion_id', DB::raw('SUM(movimiento_detalles.cantidad) as total'))
            ->pluck('total', 'denominacion_id')
            ->toArray();

        $egresosHoy = DB::table('movimiento_detalles')
            ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
            ->where('movimientos.origen_caja_id', $boveda->id)
            ->where('movimiento_detalles.estado_dinero', 'bueno')
            ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
            ->groupBy('movimiento_detalles.denominacion_id')
            ->select('movimiento_detalles.denominacion_id', DB::raw('SUM(movimiento_detalles.cantidad) as total'))
            ->pluck('total', 'denominacion_id')
            ->toArray();

        $denominaciones = Denominacion::whereIn('id', array_column($detalles, 'denominacion_id'))->get();

        foreach ($detalles as $det) {
            $denom = $denominaciones->firstWhere('id', $det['denominacion_id']);
            $cantRequerida = (int) $det['cantidad'];
            $denomId = $denom->id;

            $cantInicial = (int) ($cantidadesIniciales[$denomId] ?? 0);
            $ingresos = (int) ($ingresosHoy[$denomId] ?? 0);
            $egresos = (int) ($egresosHoy[$denomId] ?? 0);

            $disponible = $cantInicial + $ingresos - $egresos;

            if ($disponible < $cantRequerida) {
                return "Saldo disponible insuficiente para la denominación {$denom->nombre} en la Bóveda de origen. Requerido: {$cantRequerida}, Disponible: {$disponible}.";
            }
        }

        return null;
    }
}
