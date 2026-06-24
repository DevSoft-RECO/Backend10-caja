<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Movimiento extends Model
{
    protected $table = 'movimientos';

    protected $fillable = [
        'origen_caja_id',
        'destino_caja_id',
        'tipo_operacion',
        'categoria_movimiento',
        'descripcion',
        'monto_total',
        'usuario_id',
        'fecha_transaccion',
    ];

    protected $casts = [
        'monto_total' => 'decimal:2',
        'fecha_transaccion' => 'datetime',
    ];

    // Relación origen caja
    public function origenCaja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'origen_caja_id');
    }

    // Relación destino caja
    public function destinoCaja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'destino_caja_id');
    }

    // Usuario auditor que ejecuta
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    // Detalles del movimiento
    public function detalles(): HasMany
    {
        return $this->hasMany(MovimientoDetalle::class, 'movimiento_id');
    }
}
