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
        Schema::create('users', function (Blueprint $table) {
            $table->id(); // ID autoincremental local de la base de datos Hija
            $table->unsignedBigInteger('sso_id')->unique()->nullable(); // ID único de la App Madre
            $table->string('name');
            $table->string('username')->unique()->nullable();
            $table->string('email')->unique();
            $table->string('telefono')->nullable();
            $table->unsignedBigInteger('id_agencia')->nullable();
            $table->string('puesto')->nullable();
            $table->string('avatar')->nullable();
            $table->json('roles_list')->nullable(); // Guardado JIT
            $table->json('permissions_list')->nullable(); // Guardado JIT
            $table->string('jti')->nullable(); // Para validación de sesión única/sync
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
