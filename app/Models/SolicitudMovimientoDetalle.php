<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudMovimientoDetalle extends Model
{
    use HasFactory;

    protected $table = 'solicitud_movimiento_detalles';

    protected $fillable = [
        'solicitud_movimiento_id',
        'denominacion_id',
        'cantidad_buena',
        'cantidad_deteriorada',
        'subtotal',
    ];

    protected $casts = [
        'cantidad_buena' => 'integer',
        'cantidad_deteriorada' => 'integer',
        'subtotal' => 'float',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudMovimiento::class, 'solicitud_movimiento_id');
    }

    public function denominacion(): BelongsTo
    {
        return $this->belongsTo(Denominacion::class, 'denominacion_id');
    }
}
