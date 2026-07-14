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
        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            // Asumiendo que tu tabla en la DB hija se llama 'agencias'
            $table->foreignId('agencia_id')->constrained('agencias')->onDelete('cascade'); 
            $table->string('nombre'); 
            $table->enum('tipo_caja', ['boveda', 'general', 'ventanilla']);
            // El usuario_id es nullable porque las cajas se abren y cierran por distintos usuarios
            $table->foreignId('usuario_id')->nullable()->constrained('users')->onDelete('set null'); 
            $table->boolean('estado')->default(true); // true = Abierta/Activa
            $table->decimal('poliza', 15, 2)->nullable();
            $table->timestamps();

            // Índice compuesto: vital para cargar rápido todas las ventanillas de una agencia específica
            $table->index(['agencia_id', 'tipo_caja']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cajas');
    }
};
