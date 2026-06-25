<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConteoParcialDetalle extends Model
{
    protected $table = 'conteo_parcial_detalles';

    protected $fillable = [
        'conteo_parcial_id',
        'denominacion_id',
        'estado_dinero',
        'cantidad',
        'subtotal',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'cantidad' => 'integer',
    ];

    public function conteoParcial(): BelongsTo
    {
        return $this->belongsTo(ConteoParcial::class, 'conteo_parcial_id');
    }

    public function denominacion(): BelongsTo
    {
        return $this->belongsTo(Denominacion::class, 'denominacion_id');
    }
}
