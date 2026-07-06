<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolicitudMovimiento extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_movimientos';

    protected $fillable = [
        'origen_caja_id',
        'destino_caja_id',
        'tipo_operacion',
        'categoria_movimiento',
        'monto_total',
        'descripcion',
        'usuario_solicitante_id',
        'usuario_autorizador_id',
        'estado',
        'observaciones_autorizador',
        'fecha_autorizacion',
    ];

    protected $casts = [
        'monto_total' => 'float',
        'fecha_autorizacion' => 'datetime',
    ];

    public function origen(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'origen_caja_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'destino_caja_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_solicitante_id');
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_autorizador_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(SolicitudMovimientoDetalle::class, 'solicitud_movimiento_id');
    }
}
