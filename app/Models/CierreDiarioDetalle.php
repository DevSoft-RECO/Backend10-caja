<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CierreDiarioDetalle extends Model
{
    protected $table = 'cierre_diario_detalles';

    protected $fillable = [
        'cierre_diario_id',
        'denominacion_id',
        'estado_dinero',
        'cantidad',
        'subtotal',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'cantidad' => 'integer',
    ];

    public function cierreDiario(): BelongsTo
    {
        return $this->belongsTo(CierreDiario::class, 'cierre_diario_id');
    }

    public function denominacion(): BelongsTo
    {
        return $this->belongsTo(Denominacion::class, 'denominacion_id');
    }
}
