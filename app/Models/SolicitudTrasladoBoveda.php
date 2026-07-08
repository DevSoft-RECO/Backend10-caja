<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolicitudTrasladoBoveda extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_traslados_bovedas';

    protected $fillable = [
        'origen_boveda_id',
        'destino_boveda_id',
        'tipo_traslado',
        'monto_total',
        'fecha_programada',
        'repartidor',
        'comentario_peticion',
        'comentario_envio',
        'usuario_creador_id',
        'estado',
    ];

    protected $casts = [
        'monto_total' => 'float',
        'fecha_programada' => 'datetime',
    ];

    public function origenBoveda(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'origen_boveda_id');
    }

    public function destinoBoveda(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'destino_boveda_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_creador_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(SolicitudTrasladoBovedaDetalle::class, 'solicitud_traslado_id');
    }
}
