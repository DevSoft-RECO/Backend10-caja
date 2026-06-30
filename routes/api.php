<?php

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\SSOController;
use App\Http\Controllers\Cajas\DenominacionController;
use App\Http\Controllers\Cajas\CajaController;
use App\Http\Controllers\Cajas\MovimientoController;
use App\Http\Controllers\Cajas\ConteoParcialController;
use App\Http\Controllers\Cajas\CierreDiarioController;
use App\Http\Controllers\Cajas\DashboardController;

// Asegúrate de que el middleware 'sso' esté registrado en bootstrap/app.php
Route::middleware('sso')->group(function () {
    
    // Dashboard General
    Route::get('reportes/dashboard-general', [DashboardController::class, 'dashboardGeneral']);
    Route::get('cajas/{caja}/inventario-deteriorado', [DashboardController::class, 'obtenerInventarioDeteriorado']);
    
    // 🧠 Sincronización JIT (Ecosistema Madre)
    Route::get('/me', [SSOController::class, 'me']);

    // Catálogo de Denominaciones
    Route::apiResource('denominaciones', DenominacionController::class)->parameters([
        'denominaciones' => 'denominacion'
    ]);
    
    // Auditoría y Cierres
    Route::apiResource('cajas/conteos-parciales', ConteoParcialController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('cajas/cierres-diarios', CierreDiarioController::class)->only(['index', 'store', 'show']);

    Route::get('cajas/{caja}/estado-apertura', [CajaController::class, 'estadoApertura']);
    Route::post('cajas/{caja}/abrir', [CajaController::class, 'abrir']);
    Route::post('cajas/{caja}/dia-cero', [CajaController::class, 'inicializarDiaCero']);
    Route::get('cajas/{caja}/saldo-actual', [CierreDiarioController::class, 'getSaldoActual']);

    // Gestión de Cajas
    Route::apiResource('cajas', CajaController::class)->except(['destroy']); // Quitamos destroy para no romper transaccionalidad
    Route::post('cajas/{caja}/asignar-usuario', [CajaController::class, 'asignarUsuario']);

    // Movimientos
    Route::apiResource('movimientos', MovimientoController::class)->only(['index', 'store']);

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
