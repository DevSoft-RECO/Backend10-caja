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
            ->whereHas('caja', function ($q) {
                $q->where('tipo_caja', 'boveda');
            })
            ->orderBy('fecha_cierre', 'desc');

        if ($request->has('caja_id')) {
            $query->where('caja_id', $request->caja_id);
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        $cierre = CierreDiario::with(['caja.agencia', 'usuario', 'detalles.denominacion'])->findOrFail($id);

        if ($cierre->caja->tipo_caja === 'boveda') {
            // Calcular los flujos de movimientos reales para ese día específico de cierre
            $start = Carbon::parse($cierre->fecha_cierre)->startOfDay();
            $end = Carbon::parse($cierre->fecha_cierre)->endOfDay();

            $cierre->total_ingresos_dia = (float) Movimiento::where('destino_caja_id', $cierre->caja_id)
                ->where('categoria_movimiento', '!=', 'carga_inicial_dia_cero')
                ->whereBetween('fecha_transaccion', [$start, $end])
                ->sum('monto_total');

            $cierre->total_egresos_dia = (float) Movimiento::where('origen_caja_id', $cierre->caja_id)
                ->whereBetween('fecha_transaccion', [$start, $end])
                ->sum('monto_total');

            // Buscar el cierre anterior para determinar las cantidades iniciales de cada denominación
            $cierreAnterior = CierreDiario::where('caja_id', $cierre->caja_id)
                ->where('fecha_cierre', '<', $cierre->fecha_cierre)
                ->orderBy('fecha_cierre', 'desc')
                ->first();

            $detallesAnteriores = collect();
            if ($cierreAnterior) {
                $detallesAnteriores = CierreDiarioDetalle::where('cierre_diario_id', $cierreAnterior->id)->get();
            }

            // Enriquecer cada detalle con su cantidad inicial del día
            $cierre->detalles->each(function ($det) use ($detallesAnteriores) {
                $prev = $detallesAnteriores->where('denominacion_id', $det->denominacion_id)
                    ->where('estado_dinero', $det->estado_dinero)
                    ->first();
                $det->cantidad_inicial = $prev ? $prev->cantidad : 0;
                $det->subtotal_inicial = $prev ? $prev->subtotal : 0.00;
            });

            // Buscar los cierres de ventanillas y cajas asociadas en la misma agencia y fecha de cierre
            $cierre->cierres_asociados = CierreDiario::where('fecha_cierre', $cierre->fecha_cierre)
                ->whereHas('caja', function ($query) use ($cierre) {
                    $query->where('agencia_id', $cierre->caja->agencia_id)
                          ->where('tipo_caja', '!=', 'boveda');
                })
                ->with(['caja', 'usuario', 'detalles.denominacion'])
                ->get();
        } else {
            $cierre->total_ingresos_dia = 0.00;
            $cierre->total_egresos_dia = 0.00;
            $cierre->cierres_asociados = [];
        }

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
            'detalles.*.estado_dinero' => 'required|in:bueno,deteriorado,cajillas',
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

            // 4. Crear registro de Cierre Diario (Snapshot)
            $saldoInicialBueno = 0.00;
            $saldoFinalBueno = 0.00;
            $saldoInicialCajillas = 0.00;
            $saldoFinalCajillas = 0.00;
            $saldoInicialDeteriorado = 0.00;
            $saldoFinalDeteriorado = 0.00;

            if ($caja->tipo_caja === 'boveda') {
                // Obtener último cierre de Bóveda anterior para arrastrar saldos
                $cierreAnterior = CierreDiario::where('caja_id', $caja->id)
                    ->orderBy('fecha_cierre', 'desc')
                    ->first();

                if ($cierreAnterior) {
                    $saldoInicialBueno = (float) $cierreAnterior->saldo_final_bueno;
                    $saldoInicialCajillas = (float) $cierreAnterior->saldo_final_cajillas;
                    $saldoInicialDeteriorado = (float) $cierreAnterior->saldo_final_deteriorado;
                }

                // Calcular efectivo bueno arqueado hoy
                foreach ($request->detalles as $det) {
                    if ($det['estado_dinero'] === 'bueno') {
                        $denom = Denominacion::find($det['denominacion_id']);
                        $saldoFinalBueno += $denom->valor * ($det['cantidad'] ?? 0);
                    }
                }

                // Calcular total deteriorados de hoy en base al arqueo físico enviado
                foreach ($request->detalles as $det) {
                    if ($det['estado_dinero'] === 'deteriorado') {
                        $denom = Denominacion::find($det['denominacion_id']);
                        $saldoFinalDeteriorado += $denom->valor * ($det['cantidad'] ?? 0);
                    }
                }

                // Calcular total cajillas de hoy en base al arqueo físico enviado
                foreach ($request->detalles as $det) {
                    if ($det['estado_dinero'] === 'cajillas') {
                        $denom = Denominacion::find($det['denominacion_id']);
                        $saldoFinalCajillas += $denom->valor * ($det['cantidad'] ?? 0);
                    }
                }
            } else {
                // Para ventanillas el comportamiento de dinero bueno es simple
                $saldoFinalBueno = $totalFisicoDeclarado;
            }

            $cierre = CierreDiario::create([
                'caja_id' => $caja->id,
                'usuario_id' => auth()->id() ?? 1,
                'fecha_cierre' => $hoy,
                'saldo_final_fisico_declarado' => $totalFisicoDeclarado,
                'saldo_inicial_bueno' => $saldoInicialBueno,
                'saldo_final_bueno' => $saldoFinalBueno,
                'saldo_inicial_cajillas' => $saldoInicialCajillas,
                'saldo_final_cajillas' => $saldoFinalCajillas,
                'saldo_inicial_deteriorado' => $saldoInicialDeteriorado,
                'saldo_final_deteriorado' => $saldoFinalDeteriorado,
            ]);

            // Guardar detalles del desglose físico de gaveta
            foreach ($detallesParaCrear as $detalle) {
                $cierre->detalles()->create($detalle);
            }

            // 5. El Barrido Virtual: Egresar el 100% del saldo físico real a la Bóveda de la misma agencia
            if ($totalFisicoDeclarado > 0) {
                $boveda = Caja::where('agencia_id', $caja->agencia_id)
                    ->where('tipo_caja', 'boveda')
                    ->where('estado', true)
                    ->first();

                $movimientoBarrido = Movimiento::create([
                    'origen_caja_id' => $caja->id,
                    'destino_caja_id' => $boveda ? $boveda->id : null,
                    'tipo_operacion' => 'ingreso',
                    'categoria_movimiento' => 'cajilla_cierre',
                    'descripcion' => 'Barrido automático de fin de jornada tras cierre diario.',
                    'monto_total' => $totalFisicoDeclarado,
                    'usuario_id' => auth()->id() ?? 1,
                    'fecha_transaccion' => now(),
                ]);

                // Crear los detalles del movimiento de barrido basados en el arqueo físico real de la cajilla
                foreach ($detallesParaCrear as $det) {
                    if ($det['cantidad'] > 0) {
                        MovimientoDetalle::create([
                            'movimiento_id' => $movimientoBarrido->id,
                            'denominacion_id' => $det['denominacion_id'],
                            'cantidad' => $det['cantidad'],
                            'subtotal' => $det['subtotal'],
                            'estado_dinero' => 'cajillas', // Retorna a la reserva de cajillas
                        ]);
                    }
                }
            }

            // 6. Cierre del turno de la caja
            $caja->update([
                'usuario_id' => null
            ]);

            return response()->json($cierre->load('detalles.denominacion'), 201);
        });
    }
}
