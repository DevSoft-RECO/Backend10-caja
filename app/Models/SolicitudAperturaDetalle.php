<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudAperturaDetalle extends Model
{
    protected $table = 'solicitud_apertura_detalles';

    protected $fillable = [
        'solicitud_id',
        'denominacion_id',
        'cantidad',
        'subtotal',
        'estado_dinero',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'subtotal' => 'float',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudApertura::class, 'solicitud_id');
    }

    public function denominacion(): BelongsTo
    {
        return $this->belongsTo(Denominacion::class, 'denominacion_id');
    }
}
