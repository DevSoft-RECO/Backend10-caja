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
        Schema::create('descuadres_agencia', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agencia_id');
            $table->unsignedBigInteger('usuario_creador_id');
            $table->string('codigo_caja');
            $table->string('nombre_receptor');
            $table->enum('tipo_descuadre', ['FALTANTE', 'SOBRANTE']);
            $table->decimal('monto_descuadre', 15, 2);
            $table->enum('descuadre_declarado', ['SI', 'NO']);
            $table->text('solucion')->nullable();
            $table->date('fecha_descuadre');
            $table->text('comentario')->nullable();
            $table->json('archivos_adjuntos')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('agencia_id')->references('id')->on('agencias')->onDelete('cascade');
            $table->foreign('usuario_creador_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('descuadres_agencia');
    }
};
