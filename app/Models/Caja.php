<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Caja extends Model
{
    protected $table = 'cajas';

    protected $fillable = [
        'agencia_id',
        'nombre',
        'tipo_caja',
        'usuario_id',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    // Relación con tu tabla existente de Agencias
    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class, 'agencia_id');
    }

    // Relación con tu tabla JIT de Usuarios
    public function usuarioEnTurno(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
