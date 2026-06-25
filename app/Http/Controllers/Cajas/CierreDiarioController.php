<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\CierreDiario;
use App\Models\CierreDiarioDetalle;
use App\Models\Caja;
use App\Models\Denominacion;
use App\Models\Movimiento;
use App\Models\MovimientoDetalle;
use App\Services\SaldoCajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CierreDiarioController extends Controller
{
    protected $saldoService;

    public function __construct(SaldoCajaService $saldoService)
    {
        $this->saldoService = $saldoService;
    }

    public function index(Request $request)
    {
        $query = CierreDiario::with(['caja.agencia', 'usuario', 'detalles.denominacion'])
            ->orderBy('fecha_cierre', 'desc');

        if ($request->has('caja_id')) {
            $query->where('caja_id', $request->caja_id);
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        $cierre = CierreDiario::with(['caja.agencia', 'usuario', 'detalles.denominacion'])->findOrFail($id);
        return response()->json($cierre);
    }

    public function getSaldoActual(Caja $caja)
    {
        $resumen = $this->saldoService->obtenerResumenDelDia($caja->id);
        return response()->json($resumen);
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

        $caja = Caja::findOrFail($request->caja_id);
        $hoy = Carbon::today()->toDateString();

        // 1. Validar que no exista cierre para hoy
        $existeCierre = CierreDiario::where('caja_id', $caja->id)
            ->where('fecha_cierre', $hoy)
            ->exists();

        if ($existeCierre) {
            return response()->json([
                'message' => 'Esta caja ya tiene registrado un cierre para el día de hoy.'
            ], 422);
        }

        return DB::transaction(function () use ($request, $caja, $hoy) {
            // 2. Obtener los balances del sistema en tiempo real
            $resumen = $this->saldoService->obtenerResumenDelDia($caja->id);
            $saldoFinalSistema = $resumen['saldo_actual'];

            // 3. Calcular el total físico declarado (la "cajilla" que se queda)
            $totalFisicoDeclarado = 0;
            $detallesParaCrear = [];

            foreach ($request->detalles as $det) {
                $denom = Denominacion::find($det['denominacion_id']);
                $cant = $det['cantidad'] ?? 0;
                $subtotal = $denom->valor * $cant;

                $totalFisicoDeclarado += $subtotal;

                $detallesParaCrear[] = [
                    'denominacion_id' => $denom->id,
                    'estado_dinero' => $det['estado_dinero'],
                    'cantidad' => $cant,
                    'subtotal' => $subtotal,
                ];
            }

            $diferencia = $totalFisicoDeclarado - $saldoFinalSistema;

            // 4. Crear registro de Cierre Diario (Snapshot)
            $cierre = CierreDiario::create([
                'caja_id' => $caja->id,
                'usuario_id' => auth()->id() ?? 1,
                'fecha_cierre' => $hoy,
                'saldo_inicial_sistema' => $resumen['saldo_inicial'],
                'total_ingresos_sistema' => $resumen['ingresos_dia'],
                'total_egresos_sistema' => $resumen['egresos_dia'],
                'saldo_final_sistema' => $saldoFinalSistema,
                'saldo_final_fisico_declarado' => $totalFisicoDeclarado,
                'diferencia' => $diferencia,
            ]);

            // Guardar detalles del desglose físico de gaveta
            foreach ($detallesParaCrear as $detalle) {
                $cierre->detalles()->create($detalle);
            }

            // 5. El Barrido Virtual: Egresar el 100% del saldo final a la Bóveda de la misma agencia
            if ($saldoFinalSistema > 0) {
                $boveda = Caja::where('agencia_id', $caja->agencia_id)
                    ->where('tipo_caja', 'boveda')
                    ->where('estado', true)
                    ->first();

                $movimientoBarrido = Movimiento::create([
                    'origen_caja_id' => $caja->id,
                    'destino_caja_id' => $boveda ? $boveda->id : null,
                    'tipo_operacion' => 'egreso',
                    'categoria_movimiento' => 'cierre_jornada_barrido',
                    'descripcion' => 'Barrido automático de fin de jornada tras cierre diario.',
                    'monto_total' => $saldoFinalSistema,
                    'usuario_id' => auth()->id() ?? 1,
                    'fecha_transaccion' => now(),
                ]);

                // Crear un detalle genérico o detallado para el movimiento de barrido
                // Para mantener la consistencia, dividimos en un detalle representativo de lo enviado
                MovimientoDetalle::create([
                    'movimiento_id' => $movimientoBarrido->id,
                    'denominacion_id' => $detallesParaCrear[0]['denominacion_id'] ?? 1,
                    'cantidad' => 1,
                    'subtotal' => $saldoFinalSistema,
                    'estado_dinero' => 'bueno',
                ]);
            }

            // 6. Cierre del turno de la caja
            $caja->update([
                'usuario_id' => null
            ]);

            return response()->json($cierre->load('detalles.denominacion'), 201);
        });
    }
}
