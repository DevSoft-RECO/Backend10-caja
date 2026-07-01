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

    // POST /api/cajas/{caja}/abrir
    public function abrir(Request $request, Caja $caja)
    {
        $request->validate([
            'detalles' => 'required|array|min:1',
            'detalles.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles.*.estado_dinero' => 'required|in:bueno,deteriorado',
            'detalles.*.cantidad' => 'required|integer|min:0',
            'supervisor_id' => 'nullable|exists:users,id'
        ]);

        if (!$caja->usuario_id) {
            return response()->json([
                'message' => 'La caja debe tener un usuario asignado en turno para poder abrirla.'
            ], 422);
        }

        // 1. Buscar último cierre
        $ultimoCierre = CierreDiario::where('caja_id', $caja->id)
            ->orderBy('id', 'desc')
            ->first();

        $saldoEsperado = $ultimoCierre ? (float) $ultimoCierre->saldo_final_fisico_declarado : 0.00;

        // 2. Calcular total declarado por cajero
        $totalDeclarado = 0;
        $detallesMovimiento = [];

        foreach ($request->detalles as $det) {
            $denom = Denominacion::find($det['denominacion_id']);
            $cant = $det['cantidad'] ?? 0;
            $subtotal = $denom->valor * $cant;
            $totalDeclarado += $subtotal;

            if ($cant > 0) {
                $detallesMovimiento[] = [
                    'denominacion_id' => $denom->id,
                    'cantidad' => $cant,
                    'subtotal' => $subtotal,
                    'estado_dinero' => $det['estado_dinero']
                ];
            }
        }

        // 3. Validar descuadre (Opción A: Bloqueo si no hay supervisor)
        $diferencia = $totalDeclarado - $saldoEsperado;
        $descuadre = abs($diferencia) > 0.01;

        if ($descuadre && !$request->filled('supervisor_id')) {
            return response()->json([
                'message' => 'Descuadre detectado en el arqueo inicial. Se requiere la autorización de un Supervisor para proceder.',
                'descuadre' => true,
                'diferencia' => $diferencia,
                'total_declarado' => $totalDeclarado,
                'saldo_esperado' => $saldoEsperado
            ], 423); // Código 423 indica Locked / Descuadre bloqueado
        }

        // 4. Iniciar transacción e inyectar dotación inicial
        return DB::transaction(function () use ($caja, $totalDeclarado, $detallesMovimiento, $request, $descuadre, $diferencia) {
            $boveda = Caja::where('agencia_id', $caja->agencia_id)
                ->where('tipo_caja', 'boveda')
                ->where('estado', true)
                ->first();

            $descripcion = 'Dotación inicial de apertura (Arqueo de inicio).';
            if ($descuadre) {
                $supervisor = User::find($request->supervisor_id);
                $descripcion .= " AUTORIZADO CON DESCUADRE de Q" . number_format($diferencia, 2) . " por Supervisor: " . ($supervisor ? $supervisor->name : "ID " . $request->supervisor_id);
            }

            // Crear el movimiento del Libro Mayor (Bóveda -> Caja)
            // Si el monto total es 0, no inyectamos saldo contable pero dejamos pasar el registro.
            // Para cajas nuevas o que cerraron en 0, el totalDeclarado es lo que físicamente tienen.
            $movimiento = Movimiento::create([
                'origen_caja_id' => $boveda ? $boveda->id : null,
                'destino_caja_id' => $caja->id,
                'tipo_operacion' => 'egreso',
                'categoria_movimiento' => 'cajilla_apertura',
                'descripcion' => $descripcion,
                'monto_total' => $totalDeclarado,
                'usuario_id' => auth()->id() ?? 1,
                'fecha_transaccion' => now()
            ]);

            // Guardar detalles del movimiento
            foreach ($detallesMovimiento as $det) {
                MovimientoDetalle::create([
                    'movimiento_id' => $movimiento->id,
                    'denominacion_id' => $det['denominacion_id'],
                    'cantidad' => $det['cantidad'],
                    'subtotal' => $det['subtotal'],
                    'estado_dinero' => 'cajillas', // El dinero proviene de la reserva de cajillas
                ]);
            }

            return response()->json([
                'message' => 'Caja abierta y dotación inicial registrada correctamente.',
                'movimiento' => $movimiento->load('detalles.denominacion')
            ], 201);
        });
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

}
