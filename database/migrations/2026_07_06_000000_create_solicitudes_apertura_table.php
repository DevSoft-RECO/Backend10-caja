<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_apertura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas')->onDelete('cascade');
            $table->foreignId('usuario_id')->constrained('users');
            $table->foreignId('supervisor_id')->nullable()->constrained('users'); // Autorizador de descuadres
            $table->foreignId('usuario_autorizador_id')->nullable()->constrained('users'); // Encargado de bóveda
            $table->decimal('monto_total', 15, 2);
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            $table->text('observaciones')->nullable();
            $table->timestamp('fecha_autorizacion')->nullable();
            $table->timestamps();
        });

        Schema::create('solicitud_apertura_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes_apertura')->onDelete('cascade');
            $table->foreignId('denominacion_id')->constrained('denominaciones');
            $table->integer('cantidad');
            $table->decimal('subtotal', 15, 2);
            $table->enum('estado_dinero', ['bueno', 'deteriorado']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_apertura_detalles');
        Schema::dropIfExists('solicitudes_apertura');
    }
};
