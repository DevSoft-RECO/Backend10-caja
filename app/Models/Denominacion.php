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

    protected $appends = ['nombre'];

    public function getNombreAttribute()
    {
        $tipoStr = $this->tipo === 'billete' ? 'Billete' : 'Moneda';
        // Formatear con decimales si tiene centavos, si no, entero
        $valorFormateado = $this->valor == (int)$this->valor ? (int)$this->valor : number_format($this->valor, 2);
        return "{$tipoStr} de Q{$valorFormateado}";
    }
}
