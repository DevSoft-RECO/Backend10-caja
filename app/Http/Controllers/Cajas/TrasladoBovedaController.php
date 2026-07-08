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

class TrasladoBovedaController extends Controller
{
    /**
     * Obtener el listado de traslados de bóvedas
     */
    public function index(Request $request)
    {
        $query = SolicitudTrasladoBoveda::with([
            'origenBoveda.agencia',
            'destinoBoveda.agencia',
            'creador',
            'detalles.denominacion'
        ]);

        // Si se pasa una agencia o caja específica
        if ($request->has('caja_id')) {
            $cajaId = $request->input('caja_id');
            $query->where(function($q) use ($cajaId) {
                $q->where('origen_boveda_id', $cajaId)
                  ->orWhere('destino_boveda_id', $cajaId);
            });
        }

        $traslados = $query->orderBy('id', 'desc')->get();

        return response()->json($traslados);
    }

    /**
     * Crear una solicitud de traslado (sea pedir o enviar)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'boveda_origen_id' => 'required|exists:cajas,id',
            'boveda_destino_id' => 'required|exists:cajas,id|different:boveda_origen_id',
            'tipo_traslado' => 'required|in:pedir,enviar',
            'fecha_programada' => 'nullable|date',
            'repartidor' => 'nullable|string|max:255',
            'comentario_peticion' => 'nullable|string',
            'comentario_envio' => 'nullable|string',
            'detalles' => 'required|array|min:1',
            'detalles.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles.*.cantidad' => 'required|integer|min:1',
        ]);

        $bovedaOrigen = Caja::findOrFail($validated['boveda_origen_id']);
        $bovedaDestino = Caja::findOrFail($validated['boveda_destino_id']);

        if ($bovedaOrigen->tipo_caja !== 'boveda' || $bovedaDestino->tipo_caja !== 'boveda') {
            return response()->json([
                'message' => 'El traslado entre bóvedas solo se permite entre cajas de tipo boveda.'
            ], 422);
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
            $estadoInicial = $validated['tipo_traslado'] === 'enviar' ? 'enviado' : 'pendiente';

            // Crear el registro de la solicitud
            $solicitud = SolicitudTrasladoBoveda::create([
                'origen_boveda_id' => $bovedaOrigen->id,
                'destino_boveda_id' => $bovedaDestino->id,
                'tipo_traslado' => $validated['tipo_traslado'],
                'monto_total' => $montoTotal,
                'fecha_programada' => $validated['fecha_programada'] ? Carbon::parse($validated['fecha_programada']) : null,
                'repartidor' => $validated['repartidor'] ?? null,
                'comentario_peticion' => $validated['comentario_peticion'] ?? null,
                'comentario_envio' => $validated['comentario_envio'] ?? null,
                'usuario_creador_id' => auth()->id() ?? User::first()->id,
                'estado' => $estadoInicial
            ]);

            foreach ($detallesProcesados as $det) {
                SolicitudTrasladoBovedaDetalle::create(array_merge($det, [
                    'solicitud_traslado_id' => $solicitud->id
                ]));
            }

            // Si es del tipo 'enviar', registramos inmediatamente el egreso de la bóveda de origen
            if ($validated['tipo_traslado'] === 'enviar') {
                // Validar saldo disponible en la Bóveda Origen
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
                    return response()->json([
                        'message' => 'No se encontró una Caja General activa en la agencia de origen.'
                    ], 422);
                }

                // A. Registrar Movimiento de Egreso de Bóveda Origen a Caja General Origen
                $movimientoEgreso = Movimiento::create([
                    'origen_caja_id' => $bovedaOrigen->id,
                    'destino_caja_id' => $cajaGeneralOrigen->id,
                    'tipo_operacion' => 'egreso',
                    'categoria_movimiento' => 'traslado_boveda',
                    'monto_total' => $montoTotal,
                    'usuario_id' => auth()->id() ?? User::first()->id,
                    'fecha_transaccion' => Carbon::now(),
                    'comentario' => "Traslado de efectivo (salida inmediata) hacia Bóveda de Agencia {$bovedaDestino->agencia->nombre}. Repartidor: " . ($validated['repartidor'] ?? 'No especificado')
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
            }

            DB::commit();

            return response()->json([
                'message' => 'Solicitud de traslado registrada exitosamente.',
                'solicitud' => $solicitud
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al procesar el traslado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirmar la recepción de la solicitud por parte de la bóveda proveedora (para tipo 'pedir')
     */
    public function confirmarRecepcionSolicitud($id)
    {
        $solicitud = SolicitudTrasladoBoveda::findOrFail($id);

        if ($solicitud->estado !== 'pendiente') {
            return response()->json(['message' => 'La solicitud no se encuentra en estado pendiente.'], 422);
        }

        $solicitud->update(['estado' => 'solicitud_recibida']);

        return response()->json([
            'message' => 'Recepción de solicitud confirmada correctamente.',
            'solicitud' => $solicitud
        ]);
    }

    /**
     * Programar fecha de envío (para tipo 'pedir')
     */
    public function programarFecha(Request $request, $id)
    {
        $validated = $request->validate([
            'fecha_programada' => 'required|date'
        ]);

        $solicitud = SolicitudTrasladoBoveda::findOrFail($id);

        if (!in_array($solicitud->estado, ['solicitud_recibida', 'pendiente'])) {
            return response()->json(['message' => 'No se puede programar fecha en este estado.'], 422);
        }

        $solicitud->update([
            'fecha_programada' => Carbon::parse($validated['fecha_programada']),
            'estado' => 'programado'
        ]);

        return response()->json([
            'message' => 'Fecha de traslado programada correctamente.',
            'solicitud' => $solicitud
        ]);
    }

    /**
     * Enviar el efectivo físico (para tipo 'pedir', registra el egreso)
     */
    public function enviarEfectivo(Request $request, $id)
    {
        $validated = $request->validate([
            'repartidor' => 'required|string|max:255',
            'comentario_envio' => 'nullable|string'
        ]);

        $solicitud = SolicitudTrasladoBoveda::with('detalles')->findOrFail($id);

        if (!in_array($solicitud->estado, ['programado', 'solicitud_recibida'])) {
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
            // Validar saldo
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
                'comentario' => "Traslado de efectivo (salida por petición) hacia Bóveda de Agencia {$bovedaDestino->agencia->nombre}. Repartidor: {$validated['repartidor']}"
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
                'repartidor' => $validated['repartidor'],
                'comentario_envio' => $validated['comentario_envio'] ?? $solicitud->comentario_envio,
                'estado' => 'enviado'
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Efectivo enviado y egreso registrado exitosamente.',
                'solicitud' => $solicitud
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al registrar envío: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Confirmar recepción del paquete (para tipo 'pedir')
     */
    public function confirmarRecepcionPaquete($id)
    {
        $solicitud = SolicitudTrasladoBoveda::findOrFail($id);

        if ($solicitud->estado !== 'enviado') {
            return response()->json(['message' => 'La solicitud no está en estado enviado.'], 422);
        }

        $solicitud->update(['estado' => 'paquete_recibido']);

        return response()->json([
            'message' => 'Recepción de paquete confirmada.',
            'solicitud' => $solicitud
        ]);
    }

    /**
     * Marcar como enterado (para tipo 'enviar')
     */
    public function marcarEnterado($id)
    {
        $solicitud = SolicitudTrasladoBoveda::findOrFail($id);

        if ($solicitud->estado !== 'enviado') {
            return response()->json(['message' => 'La solicitud no está en estado enviado.'], 422);
        }

        $solicitud->update(['estado' => 'enterado']);

        return response()->json([
            'message' => 'Marcado como enterado.',
            'solicitud' => $solicitud
        ]);
    }

    /**
     * Confirmar el ingreso definitivo a la bóveda destino (para ambos flujos, registra el ingreso)
     */
    public function confirmarIngresoEfectivo($id)
    {
        $solicitud = SolicitudTrasladoBoveda::with('detalles')->findOrFail($id);

        // Puede ingresarse desde 'paquete_recibido' (en 'pedir') o 'enterado' / 'enviado' (en 'enviar')
        $estadosPermitidos = ['paquete_recibido', 'enterado', 'enviado'];
        if (!in_array($solicitud->estado, $estadosPermitidos)) {
            return response()->json(['message' => 'La solicitud no se encuentra en un estado válido para ingresar efectivo.'], 422);
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
            // B. Registrar Movimiento de Ingreso de Caja General Destino a Bóveda Destino
            $movimientoIngreso = Movimiento::create([
                'origen_caja_id' => $cajaGeneralDestino->id,
                'destino_caja_id' => $bovedaDestino->id,
                'tipo_operacion' => 'ingreso',
                'categoria_movimiento' => 'traslado_boveda',
                'monto_total' => $solicitud->monto_total,
                'usuario_id' => auth()->id() ?? User::first()->id,
                'fecha_transaccion' => Carbon::now(),
                'comentario' => "Confirmación de ingreso de efectivo desde Bóveda de Agencia {$bovedaOrigen->agencia->nombre}. Repartidor: " . ($solicitud->repartidor ?? 'No especificado')
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
            return response()->json(['message' => 'No se puede cancelar un traslado que ya ha sido ingresado o cancelado.'], 422);
        }

        DB::beginTransaction();
        try {
            // Si el dinero ya fue enviado pero no se ha confirmado el ingreso
            if (in_array($solicitud->estado, ['enviado', 'enterado', 'paquete_recibido'])) {
                $bovedaOrigen = Caja::findOrFail($solicitud->origen_boveda_id);

                $cajaGeneralOrigen = Caja::where('tipo_caja', 'general')
                    ->where('agencia_id', $bovedaOrigen->agencia_id)
                    ->where('estado', true)
                    ->first();

                if (!$cajaGeneralOrigen) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'No se encontró una Caja General activa en la agencia de origen para realizar la reversión.'
                    ], 422);
                }

                // Registrar el movimiento de reingreso (Ingreso)
                $movimientoIngreso = Movimiento::create([
                    'origen_caja_id' => $cajaGeneralOrigen->id,
                    'destino_caja_id' => $bovedaOrigen->id,
                    'tipo_operacion' => 'ingreso',
                    'categoria_movimiento' => 'traslado_boveda',
                    'monto_total' => $solicitud->monto_total,
                    'usuario_id' => auth()->id() ?? User::first()->id,
                    'fecha_transaccion' => Carbon::now(),
                    'comentario' => "Reversión por cancelación de traslado de envío #{$solicitud->id}."
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

            // Cambiar estado a cancelado
            $solicitud->update(['estado' => 'cancelado']);

            DB::commit();

            return response()->json([
                'message' => 'Traslado cancelado y fondos reversados correctamente.',
                'solicitud' => $solicitud
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al cancelar y reversar el traslado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper para validar saldos en la bóveda
     */
    private function validarSaldoBoveda($boveda, $detalles)
    {
        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();

        // Cierre
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

        // Ingresos y egresos hoy
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
