<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConteoParcial extends Model
{
    protected $table = 'conteos_parciales';

    protected $fillable = [
        'caja_id',
        'usuario_id',
        'fecha_hora',
        'total_fisico_declarado',
        'total_segun_sistema',
        'diferencia',
    ];

    protected $casts = [
        'fecha_hora' => 'datetime',
        'total_fisico_declarado' => 'decimal:2',
        'total_segun_sistema' => 'decimal:2',
        'diferencia' => 'decimal:2',
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
        return $this->hasMany(ConteoParcialDetalle::class, 'conteo_parcial_id');
    }
}
