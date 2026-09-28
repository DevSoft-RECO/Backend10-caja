<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\ConteoParcial;
use App\Models\ConteoParcialDetalle;
use App\Models\Denominacion;
use App\Services\SaldoCajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConteoParcialController extends Controller
{
    protected $saldoService;

    public function __construct(SaldoCajaService $saldoService)
    {
        $this->saldoService = $saldoService;
    }

    public function index(Request $request)
    {
        $query = ConteoParcial::with(['caja.agencia', 'usuario', 'detalles.denominacion'])
            ->orderBy('fecha_hora', 'desc')
            ->orderBy('id', 'desc');

        if ($request->has('caja_id')) {
            $query->where('caja_id', $request->caja_id);
        }

        if ($request->has('usuario_id')) {
            $query->where('usuario_id', $request->usuario_id);
        }

        // Filtro por fecha: por defecto solo los arqueos del día de hoy
        if ($request->has('fecha')) {
            $query->whereDate('fecha_hora', $request->fecha);
        } elseif (!$request->boolean('todas_fechas')) {
            $query->whereDate('fecha_hora', now()->toDateString());
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        $conteo = ConteoParcial::with(['caja.agencia', 'usuario', 'detalles.denominacion'])->findOrFail($id);
        return response()->json($conteo);
    }

    public function store(Request $request)
    {
        $request->validate([
            'caja_id' => 'required|exists:cajas,id',
            'detalles' => 'required|array|min:1',
            'detalles.*.denominacion_id' => 'required|exists:denominaciones,id',
            'detalles.*.estado_dinero' => 'required|in:bueno,deteriorado',
            'detalles.*.cantidad' => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($request) {
            $cajaId = $request->caja_id;
            
            // 1. Calcular total físico declarado a partir de denominaciones
            $totalFisico = 0;
            $detallesParaCrear = [];

            foreach ($request->detalles as $det) {
                $denom = Denominacion::find($det['denominacion_id']);
                $cant = $det['cantidad'] ?? 0;
                $subtotal = $denom->valor * $cant;
                
                $totalFisico += $subtotal;

                $detallesParaCrear[] = [
                    'denominacion_id' => $denom->id,
                    'estado_dinero' => $det['estado_dinero'],
                    'cantidad' => $cant,
                    'subtotal' => $subtotal,
                ];
            }

            // 2. Guardar SIEMPRE un nuevo arqueo para mantener el historial completo de control
            $usuarioId = auth()->id();
            if (!$usuarioId || !\App\Models\User::where('id', $usuarioId)->exists()) {
                $usuarioId = \App\Models\User::first()?->id ?? 1;
            }

            $conteo = ConteoParcial::create([
                'caja_id' => $cajaId,
                'usuario_id' => $usuarioId,
                'fecha_hora' => now(),
                'total_fisico_declarado' => $totalFisico,
            ]);

            // 3. Crear detalles
            foreach ($detallesParaCrear as $detalle) {
                $conteo->detalles()->create($detalle);
            }

            return response()->json($conteo->load(['detalles.denominacion', 'usuario']), 201);
        });
    }

    public function destroy($id)
    {
        // Puede recibir el ID directo del conteo o el ID de la caja
        $conteo = ConteoParcial::find($id);

        if (!$conteo) {
            $conteo = ConteoParcial::where('caja_id', $id)
                ->whereDate('fecha_hora', now()->toDateString())
                ->latest('fecha_hora')
                ->first();
        }

        if ($conteo) {
            $conteo->delete();
            return response()->json([
                'message' => 'El conteo parcial ha sido eliminado correctamente.'
            ], 200);
        }

        return response()->json([
            'message' => 'No se encontró ningún conteo para eliminar.'
        ], 404);
    }
}
