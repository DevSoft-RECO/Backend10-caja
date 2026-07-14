<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\CierreDiario;
use App\Models\Denominacion;
use App\Models\Movimiento;
use App\Models\MovimientoDetalle;
use App\Models\User;
use App\Models\SolicitudApertura;
use App\Models\SolicitudAperturaDetalle;
use Carbon\Carbon;

class CajaController extends Controller
{
    public function index(Request $request)
    {
        // Permitimos filtrar por agencia si viene en el request (muy útil para el dashboard)
        $query = Caja::with(['agencia', 'usuarioEnTurno']);

        if ($request->has('agencia_id')) {
            $query->where('agencia_id', $request->agencia_id);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'agencia_id' => 'required|exists:agencias,id',
            'nombre' => 'required|string|max:255',
            'tipo_caja' => 'required|in:boveda,general,ventanilla',
            'estado' => 'boolean',
            'poliza' => 'nullable|numeric|min:0',
        ]);

        $caja = Caja::create($validated);
        return response()->json($caja, 201);
    }

    public function show(Caja $caja)
    {
        return response()->json($caja->load(['agencia', 'usuarioEnTurno']));
    }

    public function update(Request $request, Caja $caja)
    {
        $validated = $request->validate([
            'agencia_id' => 'sometimes|required|exists:agencias,id',
            'nombre' => 'sometimes|required|string|max:255',
            'tipo_caja' => 'sometimes|required|in:boveda,general,ventanilla',
            'estado' => 'boolean',
            'poliza' => 'nullable|numeric|min:0',
        ]);

        $caja->update($validated);
        return response()->json($caja);
    }

    // Método extra para asignar un usuario a la caja (El "Login" a la ventanilla)
    public function asignarUsuario(Request $request, Caja $caja)
    {
        $validated = $request->validate([
            'usuario_id' => 'nullable|exists:users,id',
        ]);

        $caja->update(['usuario_id' => $validated['usuario_id']]);
        return response()->json([
            'message' => 'Usuario asignado correctamente',
            'caja' => $caja->load(['agencia', 'usuarioEnTurno'])
        ]);
    }

    // GET /api/cajas/{caja}/estado-apertura
    public function estadoApertura(Caja $caja)
    {
        $ultimoCierre = CierreDiario::with('detalles.denominacion')
            ->where('caja_id', $caja->id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$ultimoCierre) {
            return response()->json([
                'tiene_cierre_anterior' => false,
                'saldo_final_fisico_declarado' => 0.00,
                'detalles' => []
            ]);
        }

        return response()->json([
            'tiene_cierre_anterior' => true,
            'saldo_final_fisico_declarado' => (float) $ultimoCierre->saldo_final_fisico_declarado,
            'fecha_cierre' => $ultimoCierre->fecha_cierre,
            'detalles' => $ultimoCierre->detalles
        ]);
    }

    // POST /api/cajas/{caja}/solicitar-apertura
    public function solicitarApertura(Request $request, Caja $caja)
    {
        $request->validate([
            'detalles' => 'required|array|min:1',
            'detalles.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles.*.estado_dinero' => 'required|in:bueno,deteriorado',
            'detalles.*.cantidad' => 'required|integer|min:0',
            'supervisor_id' => 'nullable|exists:users,id',
            'observaciones' => 'nullable|string'
        ]);

        if (!$caja->usuario_id) {
            return response()->json([
                'message' => 'La caja debe tener un usuario asignado en turno para poder solicitar la apertura.'
            ], 422);
        }

        // --- VALIDACIÓN A: Caja ya abierta hoy ---
        $yaAbiertaHoy = Movimiento::where('destino_caja_id', $caja->id)
            ->where('categoria_movimiento', 'cajilla_apertura')
            ->whereBetween('fecha_transaccion', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()])
            ->exists();

        if ($yaAbiertaHoy) {
            return response()->json([
                'message' => 'Operación denegada. Esta caja ya ha sido abierta en el transcurso del día de hoy y no puede abrirse dos veces.'
            ], 422);
        }

        // Validar si ya existe una solicitud PENDIENTE para esta caja
        $solicitudPendiente = SolicitudApertura::where('caja_id', $caja->id)
            ->where('estado', 'pendiente')
            ->exists();

        if ($solicitudPendiente) {
            return response()->json([
                'message' => 'Ya existe una solicitud de apertura pendiente de revisión para esta caja.'
            ], 400);
        }

        // --- VALIDACIÓN B: Stock en Bóveda para cajillas ---
        $boveda = Caja::where('agencia_id', $caja->agencia_id)
            ->where('tipo_caja', 'boveda')
            ->where('estado', true)
            ->first();

        if ($boveda) {
            $start = Carbon::today()->startOfDay();
            $end = Carbon::today()->endOfDay();

            // Buscar último cierre de Bóveda
            $ultimoCierreBoveda = DB::table('cierres_diarios')
                ->where('caja_id', $boveda->id)
                ->orderBy('id', 'desc')
                ->first();

            foreach ($request->detalles as $det) {
                $denomId = $det['denominacion_id'];
                $cantSolicitada = (int) ($det['cantidad'] ?? 0);
                if ($cantSolicitada <= 0) continue;

                $cantidadInicialBoveda = 0;
                if ($ultimoCierreBoveda) {
                    $cantidadInicialBoveda = DB::table('cierre_diario_detalles')
                        ->where('cierre_diario_id', $ultimoCierreBoveda->id)
                        ->where('denominacion_id', $denomId)
                        ->where('estado_dinero', 'cajillas')
                        ->value('cantidad') ?? 0;
                }

                $ingresosBoveda = DB::table('movimiento_detalles')
                    ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                    ->where('movimientos.destino_caja_id', $boveda->id)
                    ->where('movimiento_detalles.denominacion_id', $denomId)
                    ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                    ->whereIn('movimientos.categoria_movimiento', ['cajilla_cierre', 'devolucion', 'cierre_jornada_barrido'])
                    ->sum('movimiento_detalles.cantidad');

                $egresosBoveda = DB::table('movimiento_detalles')
                    ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                    ->where('movimientos.origen_caja_id', $boveda->id)
                    ->where('movimiento_detalles.denominacion_id', $denomId)
                    ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                    ->whereIn('movimientos.categoria_movimiento', ['cajilla_apertura', 'abastecimiento'])
                    ->sum('movimiento_detalles.cantidad');

                $cantDisponibleBoveda = (int) ($cantidadInicialBoveda + $ingresosBoveda - $egresosBoveda);

                if ($cantSolicitada > $cantDisponibleBoveda) {
                    $denomModel = Denominacion::find($denomId);
                    return response()->json([
                        'message' => "Saldo insuficiente en Bóveda para la denominación {$denomModel->nombre}. Solicitado: {$cantSolicitada}, Disponible en Bóveda: {$cantDisponibleBoveda}."
                    ], 422);
                }
            }
        }

        // 1. Buscar último cierre
        $ultimoCierre = CierreDiario::where('caja_id', $caja->id)
            ->orderBy('id', 'desc')
            ->first();

        $saldoEsperado = $ultimoCierre ? (float) $ultimoCierre->saldo_final_fisico_declarado : 0.00;

        // 2. Calcular total declarado por cajero
        $totalDeclarado = 0;
        $detallesSolicitud = [];

        foreach ($request->detalles as $det) {
            $denom = Denominacion::find($det['denominacion_id']);
            $cant = $det['cantidad'] ?? 0;
            $subtotal = $denom->valor * $cant;
            $totalDeclarado += $subtotal;

            if ($cant > 0) {
                $detallesSolicitud[] = [
                    'denominacion_id' => $denom->id,
                    'cantidad' => $cant,
                    'subtotal' => $subtotal,
                    'estado_dinero' => $det['estado_dinero']
                ];
            }
        }

        // 3. Calcular diferencia / descuadre
        $diferencia = $totalDeclarado - $saldoEsperado;

        // 4. Crear la solicitud de apertura en la base de datos
        return DB::transaction(function () use ($caja, $totalDeclarado, $detallesSolicitud, $request) {
            $solicitud = SolicitudApertura::create([
                'caja_id' => $caja->id,
                'usuario_id' => $caja->usuario_id,
                'supervisor_id' => $request->supervisor_id,
                'monto_total' => $totalDeclarado,
                'estado' => 'pendiente',
                'observaciones' => $request->observaciones
            ]);

            foreach ($detallesSolicitud as $det) {
                SolicitudAperturaDetalle::create([
                    'solicitud_id' => $solicitud->id,
                    'denominacion_id' => $det['denominacion_id'],
                    'cantidad' => $det['cantidad'],
                    'subtotal' => $det['subtotal'],
                    'estado_dinero' => $det['estado_dinero']
                ]);
            }

            return response()->json([
                'message' => 'Solicitud de apertura enviada exitosamente para revisión.',
                'solicitud' => $solicitud->load('detalles.denominacion')
            ], 201);
        });
    }

    // GET /api/cajas/solicitudes/pendientes
    public function listarSolicitudesPendientes(Request $request)
    {
        $query = SolicitudApertura::with(['caja.agencia', 'usuario', 'supervisor', 'detalles.denominacion'])
            ->where('estado', 'pendiente');

        if ($request->has('agencia_id')) {
            $query->whereHas('caja', function($q) use ($request) {
                $q->where('agencia_id', $request->agencia_id);
            });
        }

        return response()->json($query->get());
    }

    // POST /api/cajas/solicitudes/{id}/procesar
    public function procesarSolicitud(Request $request, $id)
    {
        $request->validate([
            'accion' => 'required|in:aprobado,rechazado',
            'observaciones' => 'nullable|string'
        ]);

        $solicitud = SolicitudApertura::with(['detalles', 'caja'])->findOrFail($id);

        if ($solicitud->estado !== 'pendiente') {
            return response()->json([
                'message' => 'Esta solicitud ya ha sido procesada anteriormente.'
            ], 400);
        }

        $autorizadorId = auth()->id() ?? 1;

        if ($request->accion === 'rechazado') {
            $solicitud->update([
                'estado' => 'rechazado',
                'usuario_autorizador_id' => $autorizadorId,
                'observaciones' => $request->observaciones,
                'fecha_autorizacion' => now()
            ]);

            return response()->json([
                'message' => 'Solicitud de apertura rechazada correctamente.',
                'solicitud' => $solicitud
            ]);
        }

        // Si es Aprobado, creamos el movimiento en el Libro Mayor
        try {
            return DB::transaction(function () use ($solicitud, $request, $autorizadorId) {
                $caja = $solicitud->caja;
                
                // Buscar la bóveda de la agencia correspondiente
                $boveda = Caja::where('agencia_id', $caja->agencia_id)
                    ->where('tipo_caja', 'boveda')
                    ->where('estado', true)
                    ->first();

                // --- VALIDACIÓN: Verificar disponibilidad en Bóveda hoy al aprobar ---
                if ($boveda) {
                    $start = Carbon::today()->startOfDay();
                    $end = Carbon::today()->endOfDay();

                    $ultimoCierreBoveda = DB::table('cierres_diarios')
                        ->where('caja_id', $boveda->id)
                        ->orderBy('id', 'desc')
                        ->first();

                    foreach ($solicitud->detalles as $det) {
                        $denomId = $det->denominacion_id;
                        $cantSolicitada = (int) $det->cantidad;
                        if ($cantSolicitada <= 0) continue;

                        $cantidadInicialBoveda = 0;
                        if ($ultimoCierreBoveda) {
                            $cantidadInicialBoveda = DB::table('cierre_diario_detalles')
                                ->where('cierre_diario_id', $ultimoCierreBoveda->id)
                                ->where('denominacion_id', $denomId)
                                ->where('estado_dinero', 'cajillas')
                                ->value('cantidad') ?? 0;
                        }

                        $ingresosBoveda = DB::table('movimiento_detalles')
                            ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                            ->where('movimientos.destino_caja_id', $boveda->id)
                            ->where('movimiento_detalles.denominacion_id', $denomId)
                            ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                            ->whereIn('movimientos.categoria_movimiento', ['cajilla_cierre', 'devolucion', 'cierre_jornada_barrido'])
                            ->sum('movimiento_detalles.cantidad');

                        $egresosBoveda = DB::table('movimiento_detalles')
                            ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                            ->where('movimientos.origen_caja_id', $boveda->id)
                            ->where('movimiento_detalles.denominacion_id', $denomId)
                            ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                            ->whereIn('movimientos.categoria_movimiento', ['cajilla_apertura', 'abastecimiento'])
                            ->sum('movimiento_detalles.cantidad');

                        $cantDisponibleBoveda = (int) ($cantidadInicialBoveda + $ingresosBoveda - $egresosBoveda);

                        if ($cantSolicitada > $cantDisponibleBoveda) {
                            $denomModel = Denominacion::find($denomId);
                            // Lanzamos excepción específica para atraparla y abortar
                            throw new \Exception("Aprobación fallida: Saldo insuficiente en Bóveda para la denominación {$denomModel->nombre}. Solicitado: {$cantSolicitada}, Disponible: {$cantDisponibleBoveda}.");
                        }
                    }
                }

                $diferencia = 0;
                // Calcular descuadre si lo hubo buscando último cierre
                $ultimoCierre = CierreDiario::where('caja_id', $caja->id)
                    ->orderBy('id', 'desc')
                    ->first();
                $saldoEsperado = $ultimoCierre ? (float) $ultimoCierre->saldo_final_fisico_declarado : 0.00;
                $diferencia = $solicitud->monto_total - $saldoEsperado;
                $descuadre = abs($diferencia) > 0.01;

                $descripcion = 'Dotación inicial de apertura (Autorizada).';
                if ($descuadre && $solicitud->supervisor_id) {
                    $supervisor = User::find($solicitud->supervisor_id);
                    $descripcion .= " AUTORIZADO CON DESCUADRE de Q" . number_format($diferencia, 2) . " por Supervisor: " . ($supervisor ? $supervisor->name : "ID " . $solicitud->supervisor_id);
                }

                // Crear el movimiento del Libro Mayor (Bóveda -> Caja)
                $movimiento = Movimiento::create([
                    'origen_caja_id' => $boveda ? $boveda->id : null,
                    'destino_caja_id' => $caja->id,
                    'tipo_operacion' => 'egreso',
                    'categoria_movimiento' => 'cajilla_apertura',
                    'descripcion' => $descripcion . ($request->observaciones ? ' Obs: ' . $request->observaciones : ''),
                    'monto_total' => $solicitud->monto_total,
                    'usuario_id' => $solicitud->usuario_id,
                    'fecha_transaccion' => now()
                ]);

                // Guardar detalles del movimiento
                foreach ($solicitud->detalles as $det) {
                    MovimientoDetalle::create([
                        'movimiento_id' => $movimiento->id,
                        'denominacion_id' => $det->denominacion_id,
                        'cantidad' => $det->cantidad,
                        'subtotal' => $det->subtotal,
                        'estado_dinero' => 'cajillas', // El dinero proviene de la reserva de cajillas
                    ]);
                }

                // Actualizar estado de la solicitud
                $solicitud->update([
                    'estado' => 'aprobado',
                    'usuario_autorizador_id' => $autorizadorId,
                    'observaciones' => $request->observaciones,
                    'fecha_autorizacion' => now()
                ]);

                return response()->json([
                    'message' => 'Solicitud aprobada y caja abierta con éxito.',
                    'solicitud' => $solicitud->load('caja')
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    // POST /api/cajas/{caja}/dia-cero
    public function inicializarDiaCero(Request $request, Caja $caja)
    {
        $request->validate([
            'detalles_operaciones' => 'required|array',
            'detalles_operaciones.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles_operaciones.*.cantidad' => 'required|integer|min:0',
            
            'detalles_cajillas' => 'required|array',
            'detalles_cajillas.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles_cajillas.*.cantidad' => 'required|integer|min:0',
            
            'detalles_deteriorado' => 'required|array',
            'detalles_deteriorado.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles_deteriorado.*.cantidad' => 'required|integer|min:0',
            
            'observaciones' => 'nullable|string'
        ]);

        // 1. Candado de Seguridad Crítico: Comprobar si ya existen movimientos asociados a esta caja
        $tieneMovimientos = Movimiento::where('origen_caja_id', $caja->id)
            ->orWhere('destino_caja_id', $caja->id)
            ->exists();

        if ($tieneMovimientos) {
            return response()->json([
                'message' => 'Operación denegada. Esta caja ya tiene movimientos operativos registrados en el Libro Mayor.'
            ], 403);
        }

        // 2. Procesar compartimentos
        $ayer = now()->subDay()->toDateString();
        
        $procesarCompartimento = function ($detallesInput, $estado) {
            $total = 0.00;
            $detalles = [];
            foreach ($detallesInput as $det) {
                $denom = Denominacion::find($det['denominacion_id']);
                $cant = (int) ($det['cantidad'] ?? 0);
                if ($cant > 0) {
                    $subtotal = $denom->valor * $cant;
                    $total += $subtotal;
                    $detalles[] = [
                        'denominacion_id' => $denom->id,
                        'cantidad' => $cant,
                        'subtotal' => $subtotal,
                        'estado_dinero' => $estado
                    ];
                }
            }
            return ['total' => $total, 'detalles' => $detalles];
        };

        $resBueno = $procesarCompartimento($request->detalles_operaciones, 'bueno');
        $resCajillas = $procesarCompartimento($request->detalles_cajillas, 'cajillas'); 
        $resDeteriorado = $procesarCompartimento($request->detalles_deteriorado, 'deteriorado');

        $totalConsolidado = $resBueno['total'] + $resCajillas['total'] + $resDeteriorado['total'];

        if ($totalConsolidado <= 0) {
            return response()->json([
                'message' => 'Debe declarar al menos una cantidad mayor a 0 en cualquier compartimento para inicializar la caja.'
            ], 422);
        }

        return DB::transaction(function () use ($caja, $resBueno, $resCajillas, $resDeteriorado, $totalConsolidado, $request, $ayer) {
            
            // 3. Crear el Movimiento Histórico (Día Cero: Origen NULL -> Destino Caja)
            $movimiento = Movimiento::create([
                'origen_caja_id' => null,
                'destino_caja_id' => $caja->id,
                'tipo_operacion' => 'ingreso',
                'categoria_movimiento' => 'carga_inicial_dia_cero',
                'descripcion' => $request->observaciones ?? 'Carga de saldo inicial del Día Cero.',
                'monto_total' => $totalConsolidado,
                'usuario_id' => auth()->id() ?? 1,
                'fecha_transaccion' => now()->subDay() // Fecha de ayer
            ]);

            // Guardar detalles del movimiento
            $todosLosDetalles = array_merge($resBueno['detalles'], $resCajillas['detalles'], $resDeteriorado['detalles']);
            foreach ($todosLosDetalles as $det) {
                MovimientoDetalle::create([
                    'movimiento_id' => $movimiento->id,
                    'denominacion_id' => $det['denominacion_id'],
                    'cantidad' => $det['cantidad'],
                    'subtotal' => $det['subtotal'],
                    'estado_dinero' => $det['estado_dinero']
                ]);
            }

            // 4. Crear registro de Cierre Diario para el Día Cero
            $cierre = CierreDiario::create([
                'caja_id' => $caja->id,
                'usuario_id' => auth()->id() ?? 1,
                'fecha_cierre' => $ayer,
                'saldo_final_fisico_declarado' => $totalConsolidado,
                'saldo_inicial_bueno' => $resBueno['total'],
                'saldo_final_bueno' => $resBueno['total'],
                'saldo_inicial_cajillas' => $resCajillas['total'],
                'saldo_final_cajillas' => $resCajillas['total'],
                'saldo_inicial_deteriorado' => $resDeteriorado['total'],
                'saldo_final_deteriorado' => $resDeteriorado['total'],
            ]);

            // Guardar detalles del cierre diario
            foreach ($todosLosDetalles as $det) {
                $cierre->detalles()->create([
                    'denominacion_id' => $det['denominacion_id'],
                    'estado_dinero' => $det['estado_dinero'],
                    'cantidad' => $det['cantidad'],
                    'subtotal' => $det['subtotal']
                ]);
            }

            return response()->json([
                'message' => 'Inicialización de Día Cero registrada exitosamente en sus tres compartimentos.',
                'movimiento' => $movimiento->load('detalles.denominacion'),
                'cierre' => $cierre->load('detalles.denominacion')
            ], 201);
        });
    }

    public function obtenerStock(Caja $caja)
    {
        $denominaciones = \App\Models\Denominacion::where('activo', true)
            ->orderBy('valor', 'desc')
            ->get();

        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();

        $ultimoCierre = DB::table('cierres_diarios')
            ->where('caja_id', $caja->id)
            ->orderBy('id', 'desc')
            ->first();

        $stock = [];

        foreach ($denominaciones as $denom) {
            $denomId = $denom->id;

            // 1. Stock bueno (cajillas para bóvedas, bueno para ventanillas/general)
            $estadoDineroBueno = ($caja->tipo_caja === 'boveda') ? 'cajillas' : 'bueno';
            
            $cantInicialBueno = 0;
            if ($ultimoCierre) {
                $cantInicialBueno = DB::table('cierre_diario_detalles')
                    ->where('cierre_diario_id', $ultimoCierre->id)
                    ->where('denominacion_id', $denomId)
                    ->where('estado_dinero', $estadoDineroBueno)
                    ->value('cantidad') ?? 0;
            }

            $ingresosBuenoQuery = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.destino_caja_id', $caja->id)
                ->where('movimiento_detalles.denominacion_id', $denomId)
                ->whereBetween('movimientos.fecha_transaccion', [$start, $end]);

            if ($caja->tipo_caja === 'boveda') {
                $ingresosBuenoQuery->whereIn('movimiento_detalles.estado_dinero', ['cajillas', 'bueno']);
            } else {
                $ingresosBuenoQuery->where('movimiento_detalles.estado_dinero', 'bueno');
            }
            $ingresosBueno = $ingresosBuenoQuery->sum('movimiento_detalles.cantidad');

            $egresosBuenoQuery = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.origen_caja_id', $caja->id)
                ->where('movimiento_detalles.denominacion_id', $denomId)
                ->whereBetween('movimientos.fecha_transaccion', [$start, $end]);

            if ($caja->tipo_caja === 'boveda') {
                $egresosBuenoQuery->whereIn('movimiento_detalles.estado_dinero', ['cajillas', 'bueno']);
            } else {
                $egresosBuenoQuery->where('movimiento_detalles.estado_dinero', 'bueno');
            }
            $egresosBueno = $egresosBuenoQuery->sum('movimiento_detalles.cantidad');

            $stockBueno = (int) ($cantInicialBueno + $ingresosBueno - $egresosBueno);

            // 2. Stock deteriorado
            $cantInicialDeteriorado = 0;
            if ($ultimoCierre) {
                $cantInicialDeteriorado = DB::table('cierre_diario_detalles')
                    ->where('cierre_diario_id', $ultimoCierre->id)
                    ->where('denominacion_id', $denomId)
                    ->where('estado_dinero', 'deteriorado')
                    ->value('cantidad') ?? 0;
            }

            $ingresosDeteriorado = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.destino_caja_id', $caja->id)
                ->where('movimiento_detalles.denominacion_id', $denomId)
                ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                ->where('movimiento_detalles.estado_dinero', 'deteriorado')
                ->sum('movimiento_detalles.cantidad');

            $egresosDeteriorado = DB::table('movimiento_detalles')
                ->join('movimientos', 'movimiento_detalles.movimiento_id', '=', 'movimientos.id')
                ->where('movimientos.origen_caja_id', $caja->id)
                ->where('movimiento_detalles.denominacion_id', $denomId)
                ->whereBetween('movimientos.fecha_transaccion', [$start, $end])
                ->where('movimiento_detalles.estado_dinero', 'deteriorado')
                ->sum('movimiento_detalles.cantidad');

            $stockDeteriorado = (int) ($cantInicialDeteriorado + $ingresosDeteriorado - $egresosDeteriorado);

            $stock[] = [
                'denominacion_id' => $denomId,
                'nombre' => $denom->nombre,
                'valor' => (float) $denom->valor,
                'tipo' => $denom->tipo,
                'stock_bueno' => max(0, $stockBueno),
                'stock_deteriorado' => max(0, $stockDeteriorado),
            ];
        }

        return response()->json($stock);
    }

}
