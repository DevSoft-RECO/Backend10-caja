<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudReversion extends Model
{
    use HasFactory;

    protected $table = 'solicitudes_reversiones';

    protected $fillable = [
        'agencia_id',
        'usuario_creador_id',
        'codigo_caja',
        'nombre_cajero',
        'codigo_transaccion',
        'tipo_transaccion',
        'motivo_reversion',
        'archivos_adjuntos',
        'usuario_autorizador_id',
        'estado',
        'observaciones_autorizador',
        'fecha_autorizacion',
    ];

    protected $casts = [
        'archivos_adjuntos' => 'array',
        'fecha_autorizacion' => 'datetime',
    ];

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class, 'agencia_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_creador_id');
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_autorizador_id');
    }
}
