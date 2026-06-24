<?php

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\SSOController;
use App\Http\Controllers\Cajas\DenominacionController;
use App\Http\Controllers\Cajas\CajaController;

// Asegúrate de que el middleware 'sso' esté registrado en bootstrap/app.php
Route::middleware('sso')->group(function () {
    
    // 🧠 Sincronización JIT (Ecosistema Madre)
    Route::get('/me', [SSOController::class, 'me']);

    // Catálogo de Denominaciones
    Route::apiResource('denominaciones', DenominacionController::class)->parameters([
        'denominaciones' => 'denominacion'
    ]);
    
    // Gestión de Cajas
    Route::apiResource('cajas', CajaController::class)->except(['destroy']); // Quitamos destroy para no romper transaccionalidad
    Route::post('cajas/{caja}/asignar-usuario', [CajaController::class, 'asignarUsuario']);

    // Rutas Auxiliares para formularios
    Route::get('agencias', function () {
        return response()->json(\App\Models\Agencia::orderBy('nombre')->get());
    });
    Route::get('usuarios', function () {
        return response()->json(\App\Models\User::orderBy('name')->get());
    });

});

// ==========================================
// === BACKUP SYSTEM ===
// Endpoints internos para el sistema de respaldos de la Madre
// ==========================================
Route::post('/internal/backup', [\App\Http\Controllers\InternalBackupController::class, 'generate']);
Route::get('/internal/download-backup', [\App\Http\Controllers\InternalBackupController::class, 'download']);
Route::delete('/internal/backup', [\App\Http\Controllers\InternalBackupController::class, 'deleteFile']);
