<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CierreDiario extends Model
{
    protected $table = 'cierres_diarios';

    protected $fillable = [
        'caja_id',
        'usuario_id',
        'fecha_cierre',
        'saldo_inicial_sistema',
        'total_ingresos_sistema',
        'total_egresos_sistema',
        'saldo_final_sistema',
        'saldo_final_fisico_declarado',
        'diferencia',
    ];

    protected $casts = [
        'fecha_cierre' => 'date',
        'saldo_inicial_sistema' => 'decimal:2',
        'total_ingresos_sistema' => 'decimal:2',
        'total_egresos_sistema' => 'decimal:2',
        'saldo_final_sistema' => 'decimal:2',
        'saldo_final_fisico_declarado' => 'decimal:2',
        'diferencia' => 'decimal:2',
    ];

    protected $appends = ['resultado_cuadre'];

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(CierreDiarioDetalle::class, 'cierre_diario_id');
    }

    public function getResultadoCuadreAttribute(): string
    {
        $diff = (float) $this->diferencia;
        if (abs($diff) < 0.01) {
            return 'Cuadrado';
        }
        return $diff > 0 ? 'Sobrante' : 'Faltante';
    }
}
