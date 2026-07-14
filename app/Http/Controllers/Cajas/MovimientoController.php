<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Models\MovimientoDetalle;
use App\Models\Denominacion;
use App\Models\Caja;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\SolicitudMovimiento;
use App\Models\SolicitudMovimientoDetalle;
use Carbon\Carbon;

class MovimientoController extends Controller
{
    public function index(Request $request)
    {
        $query = Movimiento::with(['origenCaja.agencia', 'destinoCaja.agencia', 'usuario', 'detalles.denominacion'])
            ->orderBy('fecha_transaccion', 'desc');

        if ($request->has('origen_caja_id')) {
            $query->where('origen_caja_id', $request->origen_caja_id);
        }

        if ($request->has('destino_caja_id')) {
            $query->where('destino_caja_id', $request->destino_caja_id);
        }

        if ($request->has('fecha_desde')) {
            $query->whereDate('fecha_transaccion', '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta')) {
            $query->whereDate('fecha_transaccion', '<=', $request->fecha_hasta);
        }

        $perPage = $request->input('per_page', 10);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $request->validate([
            'origen_caja_id' => 'nullable|exists:cajas,id',
            'destino_caja_id' => 'nullable|exists:cajas,id|different:origen_caja_id',
            'tipo_operacion' => 'required|in:ingreso,egreso',
            'categoria_movimiento' => 'required|in:cajilla_apertura,cajilla_cierre,abastecimiento,devolucion,deteriorado',
            'descripcion' => 'nullable|string',
            'detalles' => 'required|array|min:1',
            'detalles.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles.*.cantidad_buena' => 'nullable|integer|min:0',
            'detalles.*.cantidad_deteriorada' => 'nullable|integer|min:0',
        ]);

        // Validar que al menos se envíe una cantidad > 0 en alguna denominación
        $tieneCantidades = false;
        foreach ($request->detalles as $det) {
            if (($det['cantidad_buena'] ?? 0) > 0 || ($det['cantidad_deteriorada'] ?? 0) > 0) {
                $tieneCantidades = true;
                break;
            }
        }
        if (!$tieneCantidades) {
            return response()->json(['message' => 'Debe ingresar al menos una cantidad mayor a 0 en los detalles.'], 422);
        }

        // Regla de Negocio: No se puede enviar efectivo deteriorado a una Ventanilla
        if ($request->destino_caja_id) {
            $destino = Caja::find($request->destino_caja_id);
            if ($destino->tipo_caja === 'ventanilla') {
                foreach ($request->detalles as $detalle) {
                    if (($detalle['cantidad_deteriorada'] ?? 0) > 0) {
                        return response()->json(['message' => 'Operación denegada. No se puede enviar efectivo deteriorado a una ventanilla.'], 422);
                    }
                }
            }
        }

        // Regla de Negocio: No se puede egresar efectivo deteriorado de la Bóveda
        if ($request->origen_caja_id) {
            $origen = Caja::find($request->origen_caja_id);
            if ($origen->tipo_caja === 'boveda') {
                foreach ($request->detalles as $detalle) {
                    if (($detalle['cantidad_deteriorada'] ?? 0) > 0) {
                        return response()->json(['message' => 'Operación denegada. No se puede egresar efectivo deteriorado de la Bóveda.'], 422);
                    }
                }
            }
        }

        return DB::transaction(function () use ($request) {
            // 1. Crear la cabecera del movimiento (con monto_total temporal de 0)
            $movimiento = Movimiento::create([
                'origen_caja_id' => $request->origen_caja_id,
                'destino_caja_id' => $request->destino_caja_id,
                'tipo_operacion' => $request->tipo_operacion,
                'categoria_movimiento' => $request->categoria_movimiento,
                'descripcion' => $request->descripcion,
                'monto_total' => 0, 
                'usuario_id' => auth()->id() ?? 1, // Firma digital auditora (por defecto ID 1 si no está autenticado por consola)
                'fecha_transaccion' => now(),
            ]);

            $montoTotalCalculado = 0;

            // 2. Procesar e insertar los detalles de doble entrada (buena/deteriorada)
            foreach ($request->detalles as $detalle) {
                $denominacion = Denominacion::find($detalle['denominacion_id']);

                // Procesar cantidad buena
                $cantBuena = $detalle['cantidad_buena'] ?? 0;
                if ($cantBuena > 0) {
                    $subtotalBueno = $denominacion->valor * $cantBuena;
                    
                    // Si es apertura de cajilla, el estado contable del dinero es 'cajillas'
                    $estadoDetalle = ($request->categoria_movimiento === 'cajilla_apertura') ? 'cajillas' : 'bueno';
                    
                    MovimientoDetalle::create([
                        'movimiento_id' => $movimiento->id,
                        'denominacion_id' => $denominacion->id,
                        'cantidad' => $cantBuena,
                        'subtotal' => $subtotalBueno,
                        'estado_dinero' => $estadoDetalle,
                    ]);
                    $montoTotalCalculado += $subtotalBueno;
                }

                // Procesar cantidad deteriorada
                $cantDeteriorada = $detalle['cantidad_deteriorada'] ?? 0;
                if ($cantDeteriorada > 0) {
                    $subtotalDeteriorado = $denominacion->valor * $cantDeteriorada;
                    MovimientoDetalle::create([
                        'movimiento_id' => $movimiento->id,
                        'denominacion_id' => $denominacion->id,
                        'cantidad' => $cantDeteriorada,
                        'subtotal' => $subtotalDeteriorado,
                        'estado_dinero' => 'deteriorado',
                    ]);
                    $montoTotalCalculado += $subtotalDeteriorado;
                }
            }

            // 3. Actualizar la cabecera con el monto verificado e inmutable
            $movimiento->update(['monto_total' => $montoTotalCalculado]);

            return response()->json($movimiento->load('detalles.denominacion'), 201);
        });
    }

    // POST /api/movimientos/solicitar
    public function solicitar(Request $request)
    {
        $request->validate([
            'origen_caja_id' => 'nullable|exists:cajas,id',
            'destino_caja_id' => 'nullable|exists:cajas,id|different:origen_caja_id',
            'tipo_operacion' => 'required|in:ingreso,egreso',
            'categoria_movimiento' => 'required|in:abastecimiento,devolucion,deteriorado',
            'descripcion' => 'nullable|string',
            'detalles' => 'required|array|min:1',
            'detalles.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles.*.cantidad_buena' => 'nullable|integer|min:0',
            'detalles.*.cantidad_deteriorada' => 'nullable|integer|min:0',
        ]);

        $tieneCantidades = false;
        foreach ($request->detalles as $det) {
            if (($det['cantidad_buena'] ?? 0) > 0 || ($det['cantidad_deteriorada'] ?? 0) > 0) {
                $tieneCantidades = true;
                break;
            }
        }

        if (!$tieneCantidades) {
            return response()->json(['message' => 'Debe ingresar al menos una cantidad mayor a 0 en los detalles.'], 422);
        }

        // 1. Calcular total y preparar desglose
        $montoTotal = 0;
        $detallesPayload = [];

        foreach ($request->detalles as $det) {
            $denom = Denominacion::find($det['denominacion_id']);
            $cantBuena = $det['cantidad_buena'] ?? 0;
            $cantDeteriorada = $det['cantidad_deteriorada'] ?? 0;
            
            $subtotal = ($denom->valor * $cantBuena) + ($denom->valor * $cantDeteriorada);
            $montoTotal += $subtotal;

            if ($cantBuena > 0 || $cantDeteriorada > 0) {
                $detallesPayload[] = [
                    'denominacion_id' => $denom->id,
                    'cantidad_buena' => $cantBuena,
                    'cantidad_deteriorada' => $cantDeteriorada,
                    'subtotal' => $subtotal
                ];
            }
        }

        // 2. Crear solicitud
        return DB::transaction(function () use ($request, $montoTotal, $detallesPayload) {
            $solicitud = SolicitudMovimiento::create([
                'origen_caja_id' => $request->origen_caja_id,
                'destino_caja_id' => $request->destino_caja_id,
                'tipo_operacion' => $request->tipo_operacion,
                'categoria_movimiento' => $request->categoria_movimiento,
                'monto_total' => $montoTotal,
                'descripcion' => $request->descripcion,
                'usuario_solicitante_id' => auth()->id() ?? 1,
                'estado' => 'pendiente'
            ]);

            foreach ($detallesPayload as $det) {
                SolicitudMovimientoDetalle::create([
                    'solicitud_movimiento_id' => $solicitud->id,
                    'denominacion_id' => $det['denominacion_id'],
                    'cantidad_buena' => $det['cantidad_buena'],
                    'cantidad_deteriorada' => $det['cantidad_deteriorada'],
                    'subtotal' => $det['subtotal']
                ]);
            }

            return response()->json([
                'message' => 'Solicitud de movimiento enviada exitosamente para revisión de Bóveda.',
                'solicitud' => $solicitud->load('detalles.denominacion')
            ], 201);
        });
    }

    // GET /api/movimientos/solicitudes/pendientes
    public function listarSolicitudesPendientes(Request $request)
    {
        $user = auth()->user();
        $query = SolicitudMovimiento::with(['origen.agencia', 'destino.agencia', 'solicitante', 'detalles.denominacion'])
            ->where('estado', 'pendiente');

        // Si no es Super Admin, filtrar por la agencia del usuario logueado
        if ($user && !in_array('Super Admin', $user->roles_list ?? [])) {
            $agenciaId = $user->id_agencia;
            $query->where(function($q) use ($agenciaId) {
                $q->whereHas('origen', function($sub) use ($agenciaId) {
                    $sub->where('agencia_id', $agenciaId);
                })->orWhereHas('destino', function($sub) use ($agenciaId) {
                    $sub->where('agencia_id', $agenciaId);
                });
            });
        }

        $query->orderBy('created_at', 'desc');

        return response()->json($query->get());
    }

    // DELETE /api/movimientos/solicitudes/{id}
    public function eliminarSolicitud($id)
    {
        $user = auth()->user();
        
        // Regla de seguridad: Solo Super Admin puede eliminar
        if (!$user || !in_array('Super Admin', $user->roles_list ?? [])) {
            return response()->json(['message' => 'Acción denegada. Solo los usuarios con rol de Super Admin pueden eliminar solicitudes.'], 403);
        }

        $solicitud = SolicitudMovimiento::findOrFail($id);
        
        if ($solicitud->estado !== 'pendiente') {
            return response()->json(['message' => 'Solo se pueden eliminar solicitudes en estado pendiente.'], 400);
        }

        $solicitud->delete(); // Elimina en cascada por foreign key

        return response()->json(['message' => 'Solicitud de movimiento eliminada con éxito.']);
    }

    // POST /api/movimientos/solicitudes/{id}/procesar
    public function procesarSolicitud(Request $request, $id)
    {
        $request->validate([
            'accion' => 'required|in:aprobado,rechazado',
            'observaciones' => 'nullable|string'
        ]);

        $solicitud = SolicitudMovimiento::with(['detalles', 'origen', 'destino'])->findOrFail($id);

        if ($solicitud->estado !== 'pendiente') {
            return response()->json(['message' => 'Esta solicitud ya ha sido procesada anteriormente.'], 400);
        }

        $autorizadorId = auth()->id() ?? 1;

        if ($request->accion === 'rechazado') {
            $solicitud->update([
                'estado' => 'rechazado',
                'usuario_autorizador_id' => $autorizadorId,
                'observaciones_autorizador' => $request->observaciones,
                'fecha_autorizacion' => now()
            ]);

            return response()->json([
                'message' => 'Solicitud de movimiento rechazada correctamente.',
                'solicitud' => $solicitud
            ]);
        }

        // Si es Aprobado, creamos el movimiento en el Libro Mayor
        try {
            return DB::transaction(function () use ($solicitud, $request, $autorizadorId) {
                // --- VALIDACIÓN DE STOCK: Comprobar inventario del Origen hoy al aprobar ---
                if ($solicitud->tipo_operacion === 'egreso' && $solicitud->origen_caja_id) {
                    $origen = $solicitud->origen;
                    $start = Carbon::today()->startOfDay();
                    $end = Carbon::today()->endOfDay();

                    $ultimoCierre = DB::table('cierres_diarios')
                        ->where('caja_id', $origen->id)
                        ->orderBy('id', 'desc')
                        ->first();

                    foreach ($solicitud->detalles as $det) {
                        $denomId = $det->denominacion_id;
                        $cantSolicitadaB = (int) $det->cantidad_buena;
                        $cantSolicitadaD = (int) $det->cantidad_deteriorada;
                        
                        // Validar stock de dinero bueno (cajillas / saldo neto de ventanilla)
                        if ($cantSolicitadaB > 0) {
                            $estadoDinero = ($origen->tipo_caja === 'boveda') ? 'cajillas' : 'bueno';

                            $cantidadInicial = 0;
                            if ($ultimoCierre) {
                                $cantidadInicial = DB::table('cierre_diario_detalles')
                                    ->where('cierre_diario_id', $ultimoCierre->id)
                                    ->where('denominacion_id', $denomId)
                                    ->where('estado_dinero', $estadoDinero)
                                    ->value('cantidad') ?? 0;
                            }

                            // Calcular movimientos del día para dinero bueno
                            $ingresosQuery = DB::table('movimiento_detalles')
                                 ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                                 ->where('movimientos.destino_caja_id', $origen->id)
                                 ->where('movimiento_detalles.denominacion_id', $denomId)
                                 ->whereBetween('movimientos.fecha_transaccion', [$start, $end]);

                             if ($origen->tipo_caja === 'boveda') {
                                 $ingresosQuery->whereIn('movimiento_detalles.estado_dinero', ['cajillas', 'bueno']);
                             } else {
                                 $ingresosQuery->where('movimiento_detalles.estado_dinero', 'bueno');
                             }
                             $ingresos = $ingresosQuery->sum('movimiento_detalles.cantidad');
 
                             $egresosQuery = DB::table('movimiento_detalles')
                                 ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                                 ->where('movimientos.origen_caja_id', $origen->id)
                                 ->where('movimiento_detalles.denominacion_id', $denomId)
                                 ->whereBetween('movimientos.fecha_transaccion', [$start, $end]);

                             if ($origen->tipo_caja === 'boveda') {
                                 $egresosQuery->whereIn('movimiento_detalles.estado_dinero', ['cajillas', 'bueno']);
                             } else {
                                 $egresosQuery->where('movimiento_detalles.estado_dinero', 'bueno');
                             }
                             $egresos = $egresosQuery->sum('movimiento_detalles.cantidad');

                            $cantDisponible = (int) ($cantidadInicial + $ingresos - $egresos);

                            if ($cantSolicitadaB > $cantDisponible) {
                                $denomModel = Denominacion::find($denomId);
                                throw new \Exception("Aprobación fallida: Saldo de efectivo insuficiente en el origen ({$origen->nombre}) para la denominación {$denomModel->nombre}. Solicitado: {$cantSolicitadaB}, Disponible: {$cantDisponible}.");
                            }
                        }

                        // Validar stock de dinero deteriorado
                        if ($cantSolicitadaD > 0) {
                            $cantidadInicialDet = 0;
                            if ($ultimoCierre) {
                                $cantidadInicialDet = DB::table('cierre_diario_detalles')
                                    ->where('cierre_diario_id', $ultimoCierre->id)
                                    ->where('denominacion_id', $denomId)
                                    ->where('estado_dinero', 'deteriorado')
                                    ->value('cantidad') ?? 0;
                            }

                            $ingresosDet = DB::table('movimiento_detalles')
                                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                                ->where('movimientos.destino_caja_id', $origen->id)
                                ->where('movimiento_detalles.denominacion_id', $denomId)
                                ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                                ->where('movimiento_detalles.estado_dinero', 'deteriorado')
                                ->sum('movimiento_detalles.cantidad');

                            $egresosDet = DB::table('movimiento_detalles')
                                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                                ->where('movimientos.origen_caja_id', $origen->id)
                                ->where('movimiento_detalles.denominacion_id', $denomId)
                                ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                                ->where('movimiento_detalles.estado_dinero', 'deteriorado')
                                ->sum('movimiento_detalles.cantidad');

                            $cantDisponibleDet = (int) ($cantidadInicialDet + $ingresosDet - $egresosDet);

                            if ($cantSolicitadaD > $cantDisponibleDet) {
                                $denomModel = Denominacion::find($denomId);
                                throw new \Exception("Aprobación fallida: Saldo de efectivo deteriorado insuficiente en el origen ({$origen->nombre}) para la denominación {$denomModel->nombre}. Solicitado: {$cantSolicitadaD}, Disponible: {$cantDisponibleDet}.");
                            }
                        }
                    }
                }

                // Crear el movimiento contable del Libro Mayor
                $movimiento = Movimiento::create([
                    'origen_caja_id' => $solicitud->origen_caja_id,
                    'destino_caja_id' => $solicitud->destino_caja_id,
                    'tipo_operacion' => $solicitud->tipo_operacion,
                    'categoria_movimiento' => $solicitud->categoria_movimiento,
                    'descripcion' => 'Traslado de Efectivo (Autorizado). ' . ($solicitud->descripcion ? ' Obs: ' . $solicitud->descripcion : '') . ($request->observaciones ? ' Obs Autorizador: ' . $request->observaciones : ''),
                    'monto_total' => $solicitud->monto_total,
                    'usuario_id' => $solicitud->usuario_solicitante_id,
                    'fecha_transaccion' => now()
                ]);

                // Guardar detalles del movimiento mapeando el tipo de compartimento
                foreach ($solicitud->detalles as $det) {
                    $origenTipo = $solicitud->origen ? $solicitud->origen->tipo_caja : 'boveda';
                    $destinoTipo = $solicitud->destino ? $solicitud->destino->tipo_caja : 'ventanilla';

                    if ($det->cantidad_buena > 0) {
                         // Determinar el compartimento contable
                         $estadoDinero = 'bueno';
                         $esOperacionOrdinaria = in_array($solicitud->categoria_movimiento, ['abastecimiento', 'devolucion']);
                         if (!$esOperacionOrdinaria && ($origenTipo === 'boveda' || $destinoTipo === 'boveda')) {
                             $estadoDinero = 'cajillas'; // Flujo de efectivo bueno hacia/desde Bóveda para otras operaciones (ej: apertura/cierre de cajillas)
                         }

                        MovimientoDetalle::create([
                            'movimiento_id' => $movimiento->id,
                            'denominacion_id' => $det->denominacion_id,
                            'cantidad' => $det->cantidad_buena,
                            'subtotal' => $det->subtotal,
                            'estado_dinero' => $estadoDinero,
                        ]);
                    }

                    if ($det->cantidad_deteriorada > 0) {
                        MovimientoDetalle::create([
                            'movimiento_id' => $movimiento->id,
                            'denominacion_id' => $det->denominacion_id,
                            'cantidad' => $det->cantidad_deteriorada,
                            'subtotal' => $det->subtotal,
                            'estado_dinero' => 'deteriorado',
                        ]);
                    }
                }

                // Actualizar estado de la solicitud
                $solicitud->update([
                    'estado' => 'aprobado',
                    'usuario_autorizador_id' => $autorizadorId,
                    'observaciones_autorizador' => $request->observaciones,
                    'fecha_autorizacion' => now()
                ]);

                return response()->json([
                    'message' => 'Solicitud de traslado aprobada y aplicada con éxito.',
                    'solicitud' => $solicitud->load(['origen', 'destino'])
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
