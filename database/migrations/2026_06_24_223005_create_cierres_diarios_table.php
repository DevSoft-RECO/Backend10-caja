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
        Schema::create('cierres_diarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas')->onDelete('cascade');
            $table->foreignId('usuario_id')->constrained('users');
            $table->date('fecha_cierre');
            $table->decimal('saldo_inicial_sistema', 15, 2);
            $table->decimal('total_ingresos_sistema', 15, 2);
            $table->decimal('total_egresos_sistema', 15, 2);
            $table->decimal('saldo_final_sistema', 15, 2);
            $table->decimal('saldo_final_fisico_declarado', 15, 2);
            $table->decimal('diferencia', 15, 2);
            $table->timestamps();

            $table->unique(['caja_id', 'fecha_cierre']);
        });

        Schema::create('cierre_diario_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cierre_diario_id')->constrained('cierres_diarios')->onDelete('cascade');
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
        Schema::dropIfExists('cierre_diario_detalles');
        Schema::dropIfExists('cierres_diarios');
    }
};
