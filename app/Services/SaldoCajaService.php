<?php

namespace App\Services;

use App\Models\Movimiento;
use App\Models\CierreDiario;
use Carbon\Carbon;

class SaldoCajaService
{
    /**
     * Calcula el saldo actual en tiempo real de una caja basándose en el Libro Mayor.
     */
    public function obtenerSaldoActual(int $cajaId): float
    {
        $caja = \App\Models\Caja::findOrFail($cajaId);

        if ($caja->tipo_caja === 'ventanilla') {
            // Para ventanillas: las aperturas/abastecimientos son egresos (salida de efectivo a gaveta),
            // y los cierres/devoluciones son ingresos.
            // Por lo tanto, el saldo disponible = ingresos (devoluciones/cierres) - egresos (aperturas/abastecimientos)
            // Pero como la ventanilla inicia con saldo de aperturas/abastecimientos (que son origen=boveda, destino=cajilla),
            // y disminuye con cierres/devoluciones (origen=cajilla, destino=boveda), el dinero físico en ella es:
            // Saldo Físico = sum(destino_caja_id == cajilla) - sum(origen_caja_id == cajilla)
            // Esto es contablemente igual: lo que entra a la cajilla (aperturas/abastecimientos) es sum(destino_caja_id == cajilla)
            // y lo que sale de la cajilla (cierres/devoluciones) es sum(origen_caja_id == cajilla)
            $entradas = Movimiento::where('destino_caja_id', $cajaId)->sum('monto_total');
            $salidas = Movimiento::where('origen_caja_id', $cajaId)->sum('monto_total');
            return (float) ($entradas - $salidas);
        }

        // Para Bóveda u otros:
        $ingresos = Movimiento::where('destino_caja_id', $cajaId)->sum('monto_total');
        $egresos = Movimiento::where('origen_caja_id', $cajaId)->sum('monto_total');
        return (float) ($ingresos - $egresos);
    }

    /**
     * Obtiene el acumulado de ingresos y egresos del día actual para una caja.
     */
    public function obtenerResumenDelDia(int $cajaId): array
    {
        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();
        $caja = \App\Models\Caja::findOrFail($cajaId);

        $entradasDia = Movimiento::where('destino_caja_id', $cajaId)
            ->whereBetween('fecha_transaccion', [$start, $end])
            ->sum('monto_total');

        $salidasDia = Movimiento::where('origen_caja_id', $cajaId)
            ->whereBetween('fecha_transaccion', [$start, $end])
            ->sum('monto_total');

        $saldoActual = $this->obtenerSaldoActual($cajaId);

        if ($caja->tipo_caja === 'ventanilla') {
            // Para ventanilla, físicamente:
            // "Ingresos del día" son las devoluciones/cierres de gaveta (salidas de caja hacia boveda)
            // "Egresos del día" son las aperturas/abastecimientos (entradas a caja desde boveda)
            $ingresosDia = $salidasDia;
            $egresosDia = $entradasDia;
            $saldoInicial = $saldoActual - ($egresosDia - $ingresosDia); // Saldo inicial es saldo actual - egresos + ingresos
        } else {
            $ingresosDia = $entradasDia;
            $egresosDia = $salidasDia;
            $saldoInicial = $saldoActual - ($ingresosDia - $egresosDia);
        }

        return [
            'saldo_inicial' => (float) $saldoInicial,
            'ingresos_dia' => (float) $ingresosDia,
            'egresos_dia' => (float) $egresosDia,
            'saldo_actual' => (float) $saldoActual,
        ];
    }
}
