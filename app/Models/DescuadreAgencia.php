<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DescuadreAgencia extends Model
{
    use HasFactory;

    protected $table = 'descuadres_agencia';

    protected $fillable = [
        'agencia_id',
        'usuario_creador_id',
        'codigo_caja',
        'nombre_receptor',
        'tipo_descuadre',
        'monto_descuadre',
        'descuadre_declarado',
        'solucion',
        'fecha_descuadre',
        'comentario',
        'archivos_adjuntos',
    ];

    protected $casts = [
        'archivos_adjuntos' => 'array',
        'fecha_descuadre' => 'date',
        'monto_descuadre' => 'decimal:2',
    ];

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class, 'agencia_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_creador_id');
    }
}
