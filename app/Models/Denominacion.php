<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Denominacion extends Model
{
    protected $table = 'denominaciones';

    protected $fillable = [
        'valor',
        'tipo',
        'activo',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'activo' => 'boolean',
    ];
}
