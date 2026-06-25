<?php

namespace App\Services;

use App\Models\Movimiento;
use App\Models\CierreDiario;
use Carbon\Carbon;

class SaldoCajaService
{
    /**
     * Calcula el saldo actual en tiempo real de una caja basándose en el Libro Mayor.
     * Saldo = Suma(Ingresos) - Suma(Egresos)
     */
    public function obtenerSaldoActual(int $cajaId): float
    {
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

        $ingresosDia = Movimiento::where('destino_caja_id', $cajaId)
            ->whereBetween('fecha_transaccion', [$start, $end])
            ->sum('monto_total');

        $egresosDia = Movimiento::where('origen_caja_id', $cajaId)
            ->whereBetween('fecha_transaccion', [$start, $end])
            ->sum('monto_total');

        // El saldo inicial del día en sistema es el saldo actual menos lo operado hoy
        $saldoActual = $this->obtenerSaldoActual($cajaId);
        $saldoInicial = $saldoActual - ($ingresosDia - $egresosDia);

        return [
            'saldo_inicial' => (float) $saldoInicial,
            'ingresos_dia' => (float) $ingresosDia,
            'egresos_dia' => (float) $egresosDia,
            'saldo_actual' => (float) $saldoActual,
        ];
    }
}
