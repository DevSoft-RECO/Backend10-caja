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
        'saldo_final_fisico_declarado',
        'saldo_inicial_bueno',
        'saldo_final_bueno',
        'saldo_inicial_cajillas',
        'saldo_final_cajillas',
        'saldo_inicial_deteriorado',
        'saldo_final_deteriorado',
    ];

    protected $casts = [
        'fecha_cierre' => 'date',
        'saldo_final_fisico_declarado' => 'decimal:2',
        'saldo_inicial_bueno' => 'decimal:2',
        'saldo_final_bueno' => 'decimal:2',
        'saldo_inicial_cajillas' => 'decimal:2',
        'saldo_final_cajillas' => 'decimal:2',
        'saldo_inicial_deteriorado' => 'decimal:2',
        'saldo_final_deteriorado' => 'decimal:2',
    ];

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
}
