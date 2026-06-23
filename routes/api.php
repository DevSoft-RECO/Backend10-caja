<?php

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\SSOController;

// Asegúrate de que el middleware 'sso' esté registrado en bootstrap/app.php
Route::middleware('sso')->group(function () {
    
    // 🧠 Sincronización JIT (Ecosistema Madre)
    Route::get('/me', [SSOController::class, 'me']);

});

// ==========================================
// === BACKUP SYSTEM ===
// Endpoints internos para el sistema de respaldos de la Madre
// ==========================================
Route::post('/internal/backup', [\App\Http\Controllers\InternalBackupController::class, 'generate']);
Route::get('/internal/download-backup', [\App\Http\Controllers\InternalBackupController::class, 'download']);
Route::delete('/internal/backup', [\App\Http\Controllers\InternalBackupController::class, 'deleteFile']);
