<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolicitudApertura extends Model
{
    protected $table = 'solicitudes_apertura';

    protected $fillable = [
        'caja_id',
        'usuario_id',
        'supervisor_id',
        'usuario_autorizador_id',
        'monto_total',
        'estado',
        'observaciones',
        'fecha_autorizacion',
    ];

    protected $casts = [
        'fecha_autorizacion' => 'datetime',
        'monto_total' => 'float',
    ];

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_autorizador_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(SolicitudAperturaDetalle::class, 'solicitud_id');
    }
}
