<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            // Claves foráneas (pueden ser null si viene de/va hacia un banco externo)
            $table->foreignId('origen_caja_id')->nullable()->constrained('cajas');
            $table->foreignId('destino_caja_id')->nullable()->constrained('cajas');
            
            $table->enum('tipo_operacion', ['ingreso', 'egreso']); 
            $table->string('categoria_movimiento'); // ej: 'dotacion', 'excedente', 'remesa', 'traslado_deteriorado'
            $table->text('descripcion')->nullable();
            $table->decimal('monto_total', 15, 2);
            
            // El usuario que realiza la operación (Firma digital de auditoría)
            $table->foreignId('usuario_id')->constrained('users'); 
            $table->timestamp('fecha_transaccion');
            $table->timestamps();

            // Índices para velocidad en reportes y cuadros
            $table->index('origen_caja_id');
            $table->index('destino_caja_id');
            $table->index('fecha_transaccion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos');
    }
};
