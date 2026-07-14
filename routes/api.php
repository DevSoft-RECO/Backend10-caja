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
use App\Http\Controllers\Cajas\DashboardPrincipalController;
use App\Http\Controllers\Cajas\TrasladoBovedaController;
use App\Http\Controllers\Cajas\BancosOperacionController;
use App\Http\Controllers\Cajas\SolicitudReversionController;

// Asegúrate de que el middleware 'sso' esté registrado en bootstrap/app.php
Route::middleware('sso')->group(function () {
    
    // Dashboard General
    Route::get('reportes/dashboard-general', [DashboardController::class, 'dashboardGeneral']);
    Route::get('reportes/saldos-agencias', [DashboardPrincipalController::class, 'saldosAgencias']);
    Route::get('cajas/{caja}/inventario-deteriorado', [DashboardController::class, 'obtenerInventarioDeteriorado']);
    Route::get('cajas/{caja}/inventario-cajillas', [DashboardController::class, 'obtenerInventarioCajillas']);
    
    // 🧠 Sincronización JIT (Ecosistema Madre)
    Route::get('/me', [SSOController::class, 'me']);

    // Catálogo de Denominaciones
    Route::apiResource('denominaciones', DenominacionController::class)->parameters([
        'denominaciones' => 'denominacion'
    ]);
    
    // Auditoría y Cierres
    Route::apiResource('cajas/conteos-parciales', ConteoParcialController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('cajas/cierres-diarios', CierreDiarioController::class)->only(['index', 'store', 'show']);

    // Traslados entre Bóvedas (Debe ir antes de cajas/{caja} para evitar colisión de wildcards)
    Route::get('cajas/traslado-bovedas', [TrasladoBovedaController::class, 'index']);
    Route::post('cajas/traslado-bovedas', [TrasladoBovedaController::class, 'store']);
    Route::post('cajas/traslado-bovedas/{id}/confirmar-recepcion', [TrasladoBovedaController::class, 'confirmarRecepcionSolicitud']);
    Route::post('cajas/traslado-bovedas/{id}/programar-fecha', [TrasladoBovedaController::class, 'programarFecha']);
    Route::post('cajas/traslado-bovedas/{id}/enviar-efectivo', [TrasladoBovedaController::class, 'enviarEfectivo']);
    Route::post('cajas/traslado-bovedas/{id}/confirmar-recepcion-paquete', [TrasladoBovedaController::class, 'confirmarRecepcionPaquete']);
    Route::post('cajas/traslado-bovedas/{id}/marcar-enterado', [TrasladoBovedaController::class, 'marcarEnterado']);
    Route::post('cajas/traslado-bovedas/{id}/confirmar-ingreso', [TrasladoBovedaController::class, 'confirmarIngresoEfectivo']);
    Route::delete('cajas/traslado-bovedas/{id}', [TrasladoBovedaController::class, 'destroy']);

    Route::get('cajas/{caja}/estado-apertura', [CajaController::class, 'estadoApertura']);
    Route::post('cajas/{caja}/solicitar-apertura', [CajaController::class, 'solicitarApertura']);
    Route::get('cajas/solicitudes/pendientes', [CajaController::class, 'listarSolicitudesPendientes']);
    Route::post('cajas/solicitudes/{id}/procesar', [CajaController::class, 'procesarSolicitud']);
    Route::post('cajas/{caja}/dia-cero', [CajaController::class, 'inicializarDiaCero']);
    Route::get('cajas/{caja}/saldo-actual', [CierreDiarioController::class, 'getSaldoActual']);
    Route::get('cajas/{caja}/stock-denominaciones', [CajaController::class, 'obtenerStock']);

    // Gestión de Cajas
    Route::apiResource('cajas', CajaController::class)->except(['destroy']); // Quitamos destroy para no romper transaccionalidad
    Route::post('cajas/{caja}/asignar-usuario', [CajaController::class, 'asignarUsuario']);

    // Movimientos
    Route::apiResource('movimientos', MovimientoController::class)->only(['index', 'store']);
    Route::post('movimientos/solicitar', [MovimientoController::class, 'solicitar']);
    Route::get('movimientos/solicitudes/pendientes', [MovimientoController::class, 'listarSolicitudesPendientes']);
    Route::post('movimientos/solicitudes/{id}/procesar', [MovimientoController::class, 'procesarSolicitud']);
    Route::delete('movimientos/solicitudes/{id}', [MovimientoController::class, 'eliminarSolicitud']);
    Route::post('cajas/bancos-operacion', [BancosOperacionController::class, 'store']);

    // Reversiones de Caja
    Route::get('reversiones', [SolicitudReversionController::class, 'index']);
    Route::post('reversiones', [SolicitudReversionController::class, 'store']);
    Route::post('reversiones/{id}/procesar', [SolicitudReversionController::class, 'procesar']);
    Route::delete('reversiones/{id}', [SolicitudReversionController::class, 'destroy']);

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
