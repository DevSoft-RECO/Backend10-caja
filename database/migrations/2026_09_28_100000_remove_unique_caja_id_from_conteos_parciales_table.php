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
        Schema::table('conteos_parciales', function (Blueprint $table) {
            // Reemplazar índice unique por un índice normal para permitir historial de arqueos
            $table->dropForeign(['caja_id']);
            $table->dropUnique(['caja_id']);
            $table->foreign('caja_id')->references('id')->on('cajas')->onDelete('cascade');
            $table->index('fecha_hora');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conteos_parciales', function (Blueprint $table) {
            $table->dropIndex(['fecha_hora']);
            $table->unique('caja_id');
        });
    }
};
