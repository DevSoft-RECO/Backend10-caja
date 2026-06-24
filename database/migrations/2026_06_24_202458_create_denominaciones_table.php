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
        Schema::create('denominaciones', function (Blueprint $table) {
            $table->id();
            $table->decimal('valor', 10, 2); // Ej: 200.00, 0.50
            $table->enum('tipo', ['billete', 'moneda']);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            // Índices para búsquedas rápidas al cargar el dashboard
            $table->index(['tipo', 'activo']); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('denominaciones');
    }
};
