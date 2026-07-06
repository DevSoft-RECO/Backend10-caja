<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function deserts(): void
    {
        //
    }

    public function up(): void
    {
        Schema::create('solicitudes_movimientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('origen_caja_id')->nullable();
            $table->unsignedBigInteger('destino_caja_id')->nullable();
            $table->enum('tipo_operacion', ['ingreso', 'egreso']);
            $table->string('categoria_movimiento'); // abastecimiento, devolucion, deteriorado
            $table->decimal('monto_total', 15, 2);
            $table->text('descripcion')->nullable();
            
            // Relación con el Cajero que solicita
            $table->unsignedBigInteger('usuario_solicitante_id');
            // Relación con el Encargado que aprueba/rechaza
            $table->unsignedBigInteger('usuario_autorizador_id')->nullable();
            
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            $table->text('observaciones_autorizador')->nullable();
            $table->timestamp('fecha_autorizacion')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('origen_caja_id')->references('id')->on('cajas')->onDelete('set null');
            $table->foreign('destino_caja_id')->references('id')->on('cajas')->onDelete('set null');
            $table->foreign('usuario_solicitante_id')->references('id')->on('users');
            $table->foreign('usuario_autorizador_id')->references('id')->on('users');
        });

        Schema::create('solicitud_movimiento_detalles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('solicitud_movimiento_id');
            $table->unsignedBigInteger('denominacion_id');
            $table->integer('cantidad_buena')->default(0);
            $table->integer('cantidad_deteriorada')->default(0);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();

            // Foreign keys
            $table->foreign('solicitud_movimiento_id', 'fk_sol_mov_det_sol_id')
                  ->references('id')
                  ->on('solicitudes_movimientos')
                  ->onDelete('cascade');
            $table->foreign('denominacion_id')->references('id')->on('denominaciones');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitud_movimiento_detalles');
        Schema::dropIfExists('solicitudes_movimientos');
    }
};
