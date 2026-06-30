<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Models\MovimientoDetalle;
use App\Models\Denominacion;
use App\Models\Caja;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

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

        return response()->json($query->get());
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
                    MovimientoDetalle::create([
                        'movimiento_id' => $movimiento->id,
                        'denominacion_id' => $denominacion->id,
                        'cantidad' => $cantBuena,
                        'subtotal' => $subtotalBueno,
                        'estado_dinero' => 'bueno',
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
}
