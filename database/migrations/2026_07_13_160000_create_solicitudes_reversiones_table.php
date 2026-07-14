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
        Schema::create('solicitudes_reversiones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agencia_id');
            $table->unsignedBigInteger('usuario_creador_id');
            $table->string('codigo_caja');
            $table->string('nombre_cajero');
            $table->string('codigo_transaccion');
            $table->string('tipo_transaccion');
            $table->text('motivo_reversion');
            $table->json('archivos_adjuntos')->nullable();
            
            // Campos de autorización
            $table->unsignedBigInteger('usuario_autorizador_id')->nullable();
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            $table->text('observaciones_autorizador')->nullable();
            $table->timestamp('fecha_autorizacion')->nullable();
            
            $table->timestamps();

            // Foreign keys
            $table->foreign('agencia_id')->references('id')->on('agencias')->onDelete('cascade');
            $table->foreign('usuario_creador_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('usuario_autorizador_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitudes_reversiones');
    }
};
