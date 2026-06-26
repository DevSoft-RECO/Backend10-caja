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
        $query = ConteoParcial::with(['caja.agencia', 'usuario', 'detalles.denominacion']);

        if ($request->has('caja_id')) {
            $query->where('caja_id', $request->caja_id);
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

            // 2. Buscar si ya existe un arqueo para esta caja
            $conteo = ConteoParcial::where('caja_id', $cajaId)->first();

            if ($conteo) {
                // Actualizar cabecera existente
                $conteo->update([
                    'usuario_id' => auth()->id() ?? 1,
                    'fecha_hora' => now(),
                    'total_fisico_declarado' => $totalFisico,
                ]);
                // Eliminar detalles previos
                $conteo->detalles()->delete();
            } else {
                // Crear cabecera
                $conteo = ConteoParcial::create([
                    'caja_id' => $cajaId,
                    'usuario_id' => auth()->id() ?? 1,
                    'fecha_hora' => now(),
                    'total_fisico_declarado' => $totalFisico,
                ]);
            }

            // 3. Crear detalles
            foreach ($detallesParaCrear as $detalle) {
                $conteo->detalles()->create($detalle);
            }

            return response()->json($conteo->load('detalles.denominacion'), 200);
        });
    }

    public function destroy($cajaId)
    {
        $conteo = ConteoParcial::where('caja_id', $cajaId)->first();
        if ($conteo) {
            $conteo->delete();
            return response()->json([
                'message' => 'El conteo parcial ha sido limpiado correctamente.'
            ], 200);
        }
        return response()->json([
            'message' => 'No se encontró ningún conteo para limpiar.'
        ], 404);
    }
}
