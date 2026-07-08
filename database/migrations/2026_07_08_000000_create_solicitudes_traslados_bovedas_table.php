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
        Schema::create('solicitudes_traslados_bovedas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('origen_boveda_id');
            $table->unsignedBigInteger('destino_boveda_id');
            $table->enum('tipo_traslado', ['pedir', 'enviar']);
            $table->decimal('monto_total', 15, 2);
            $table->timestamp('fecha_programada')->nullable();
            $table->string('repartidor')->nullable();
            $table->text('comentario_peticion')->nullable();
            $table->text('comentario_envio')->nullable();
            $table->unsignedBigInteger('usuario_creador_id');
            $table->enum('estado', ['pendiente', 'solicitud_recibida', 'programado', 'enviado', 'paquete_recibido', 'enterado', 'ingresado', 'cancelado'])->default('pendiente');
            $table->timestamps();

            // Foreign keys
            $table->foreign('origen_boveda_id')->references('id')->on('cajas')->onDelete('cascade');
            $table->foreign('destino_boveda_id')->references('id')->on('cajas')->onDelete('cascade');
            $table->foreign('usuario_creador_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('solicitud_traslado_boveda_detalles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('solicitud_traslado_id');
            $table->unsignedBigInteger('denominacion_id');
            $table->integer('cantidad')->default(0);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();

            // Foreign keys
            $table->foreign('solicitud_traslado_id', 'fk_sol_tras_det_sol_id')
                  ->references('id')
                  ->on('solicitudes_traslados_bovedas')
                  ->onDelete('cascade');
            $table->foreign('denominacion_id')->references('id')->on('denominaciones')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitud_traslado_boveda_detalles');
        Schema::dropIfExists('solicitudes_traslados_bovedas');
    }
};
