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
        Schema::create('conteos_parciales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->unique()->constrained('cajas')->onDelete('cascade');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamp('fecha_hora');
            $table->decimal('total_fisico_declarado', 15, 2);
            $table->timestamps();
        });

        Schema::create('conteo_parcial_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conteo_parcial_id')->constrained('conteos_parciales')->onDelete('cascade');
            $table->foreignId('denominacion_id')->constrained('denominaciones');
            $table->enum('estado_dinero', ['bueno', 'deteriorado']);
            $table->integer('cantidad');
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conteo_parcial_detalles');
        Schema::dropIfExists('conteos_parciales');
    }
};
