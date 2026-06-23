<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agencia extends Model
{
    protected $fillable = [
        'id',
        'nombre',
        'codigo',
        'codigot24',
        'direccion',
    ];

    public $incrementing = false; // IDs sincronizados con Ecosistema Madre
}
