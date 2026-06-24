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
        Schema::create('movimiento_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimiento_id')->constrained('movimientos')->cascadeOnDelete();
            $table->foreignId('denominacion_id')->constrained('denominaciones');
            $table->integer('cantidad');
            $table->decimal('subtotal', 15, 2);
            
            // ¡Clave para la lógica de la bóveda!
            $table->enum('estado_dinero', ['bueno', 'deteriorado'])->default('bueno'); 
            $table->timestamps();

            // Índice compuesto para buscar rápidamente dinero deteriorado
            $table->index(['movimiento_id', 'estado_dinero']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimiento_detalles');
    }
};
