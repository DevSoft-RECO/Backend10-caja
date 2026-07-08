<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudTrasladoBovedaDetalle extends Model
{
    use HasFactory;

    protected $table = 'solicitud_traslado_boveda_detalles';

    protected $fillable = [
        'solicitud_traslado_id',
        'denominacion_id',
        'cantidad',
        'subtotal',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'cantidad' => 'integer',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudTrasladoBoveda::class, 'solicitud_traslado_id');
    }

    public function denominacion(): BelongsTo
    {
        return $this->belongsTo(Denominacion::class, 'denominacion_id');
    }
}
