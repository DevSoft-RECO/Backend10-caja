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
            ->orderBy('fecha_hora', 'desc');

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
            
            // 1. Obtener saldo actual en el sistema
            $totalSistema = $this->saldoService->obtenerSaldoActual($cajaId);

            // 2. Calcular total físico declarado a partir de denominaciones
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

            $diferencia = $totalFisico - $totalSistema;

            // 3. Crear cabecera
            $conteo = ConteoParcial::create([
                'caja_id' => $cajaId,
                'usuario_id' => auth()->id() ?? 1,
                'fecha_hora' => now(),
                'total_fisico_declarado' => $totalFisico,
                'total_segun_sistema' => $totalSistema,
                'diferencia' => $diferencia,
            ]);

            // 4. Crear detalles
            foreach ($detallesParaCrear as $detalle) {
                $conteo->detalles()->create($detalle);
            }

            return response()->json($conteo->load('detalles.denominacion'), 201);
        });
    }
}
