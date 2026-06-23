<?php

use Illuminate\Support\Facades\Route;


/**
 * 🕷️ 8. Arquitectura Anti-JSON (Capa de redirección)
 * Si Laravel intenta empujar al usuario a /login por falta de sesión en el navegador,
 * lo redirigimos limpiamente a la interfaz de Vue con el flag de expiración.
 */
Route::get('/login', function () {
    $frontendUrl = env('APP_URL_FRONTEND', 'http://localhost:5173');
    return redirect($frontendUrl . '/login?session_expired=true');
})->name('login');

