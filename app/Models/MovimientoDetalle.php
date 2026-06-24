<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoDetalle extends Model
{
    protected $table = 'movimiento_detalles';

    protected $fillable = [
        'movimiento_id',
        'denominacion_id',
        'cantidad',
        'subtotal',
        'estado_dinero',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
    ];

    // Relación movimiento
    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(Movimiento::class, 'movimiento_id');
    }

    // Relación denominación
    public function denominacion(): BelongsTo
    {
        return $this->belongsTo(Denominacion::class, 'denominacion_id');
    }
}
