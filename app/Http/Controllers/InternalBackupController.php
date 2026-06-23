<?php

namespace App\Http\Controllers;

// ==========================================
// === BACKUP SYSTEM ===
// Controlador Interno de Respaldos para la Hija
// ==========================================

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InternalBackupController extends Controller
{
    /**
     * Inicia el proceso de backup en segundo plano.
     * POST /api/internal/backup
     */
    public function generate(Request $request)
    {
        try {
            $token = config('backups.token');
            if (!$token) {
                Log::error("Backup Hija: Token de respaldo no configurado.");
                return response()->json(['error' => 'Token de respaldo no configurado en la hija.'], 500);
            }

            $signature = $request->header('X-Signature');
            $timestamp = $request->header('X-Timestamp');

            // 1. Validar expiración (máximo 5 minutos)
            if (abs(time() - (int)$timestamp) > 300) {
                return response()->json(['error' => 'Petición expirada.'], 403);
            }

            // 2. Validar firma HMAC-SHA256
            $payload = json_encode([
                'timestamp' => (int)$timestamp,
                'callback_url' => $request->input('callback_url'),
                'user_id' => (int)$request->input('user_id'),
                'app_key' => $request->input('app_key'),
            ]);

            $expectedSignature = hash_hmac('sha256', $timestamp . $payload, $token);

            if (!hash_equals($expectedSignature, (string)$signature)) {
                Log::error("Backup Hija: Firma no autorizada o manipulada.");
                return response()->json(['error' => 'No autorizado. Firma no coincide.'], 401);
            }

            // 3. Preparar nombre de archivo
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $extension = $isWindows ? '.sql' : '.sql.gz'; // Laragon puede no tener gzip por defecto
            $filename = 'backup_' . $request->input('app_key') . '_' . date('Ymd_His') . $extension;

            // Crear carpeta de respaldo si no existe
            $backupDir = storage_path('app/backups');
            if (!file_exists($backupDir)) {
                try {
                    mkdir($backupDir, 0755, true);
                } catch (\Exception $ex) {
                    Log::error("Backup Hija: No se pudo crear el directorio: " . $ex->getMessage());
                    return response()->json(['error' => 'Error al crear la carpeta de respaldos en storage.'], 500);
                }
            }

            // Limpieza de respaldos huérfanos anteriores (más de 1 hora de antigüedad)
            if (file_exists($backupDir)) {
                foreach (glob($backupDir . '/*') as $file) {
                    if (is_file($file) && (time() - filemtime($file) > 3600)) {
                        @unlink($file);
                    }
                }
            }

            // 4. Lanzar el Artisan Command en background (independiente del SO)
            $callbackUrl = $request->input('callback_url');
            $userId = $request->input('user_id');
            $appKey = $request->input('app_key');

            if ($isWindows) {
                if (!function_exists('popen')) {
                    return response()->json(['error' => 'La función popen está deshabilitada en php.ini.'], 500);
                }
                // En Windows (Laragon / Desarrollo)
                $phpBinary = PHP_BINARY;
                if (str_contains($phpBinary, 'php-cgi.exe')) {
                    $phpBinary = str_replace('php-cgi.exe', 'php.exe', $phpBinary);
                }
                $artisanCmd = $phpBinary . " \"" . base_path('artisan') . "\" db:backup-worker --file=\"{$filename}\" --callback=\"{$callbackUrl}\" --user={$userId} --app=\"{$appKey}\"";
                pclose(popen("start /B {$artisanCmd}", "r"));
            } else {
                if (!function_exists('exec')) {
                    Log::error("Backup Hija: La función exec está deshabilitada en php.ini.");
                    return response()->json(['error' => 'La función exec() está deshabilitada en el servidor.'], 500);
                }
                // En Linux / Ubuntu (Producción)
                $artisanCmd = "php \"" . base_path('artisan') . "\" db:backup-worker --file=\"{$filename}\" --callback=\"{$callbackUrl}\" --user={$userId} --app=\"{$appKey}\"";
                exec("{$artisanCmd} > /dev/null 2>&1 &");
            }

            Log::info("Backup Hija: Tarea en segundo plano iniciada para {$filename}");

            return response()->json([
                'status' => 'success',
                'message' => 'Proceso de respaldo iniciado asíncronamente en la hija.'
            ], 202);

        } catch (\Exception $e) {
            Log::error("Backup Hija: Excepción fatal: " . $e->getMessage());
            return response()->json([
                'error' => 'Excepción fatal en la hija: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Endpoint para descargar el archivo.
     * GET /api/internal/download-backup
     */
    public function download(Request $request)
    {
        $token = config('backups.token');
        $filename = $request->query('file');
        $timestamp = $request->query('timestamp');
        $signature = $request->query('signature');

        // 1. Validar expiración (máximo 15 minutos para iniciar la descarga)
        if (abs(time() - (int)$timestamp) > 900) {
            return response()->json(['error' => 'El enlace de descarga ha expirado.'], 403);
        }

        // 2. Validar firma HMAC-SHA256 de descarga
        $payload = json_encode([
            'file' => $filename,
            'timestamp' => (int)$timestamp
        ]);

        $expectedSignature = hash_hmac('sha256', $timestamp . $payload, $token);

        if (!hash_equals($expectedSignature, (string)$signature)) {
            Log::error("Backup Hija: Intento de descarga con firma incorrecta.");
            return response()->json(['error' => 'Firma de descarga inválida.'], 401);
        }

        $filePath = storage_path('app/backups') . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($filePath)) {
            Log::error("Backup Hija: Archivo no encontrado: {$filename}");
            return response()->json(['error' => 'El archivo solicitado ya no existe o ya fue descargado.'], 404);
        }

        Log::info("Backup Hija: Sirviendo descarga de {$filename}.");

        // Limpiar el búfer de salida para evitar que espacios en blanco u otros datos corrompan el archivo binario
        if (ob_get_level()) {
            ob_end_clean();
        }

        // 3. Servir el archivo, desactivando buffering en Nginx
        return response()->download($filePath, null, [
            'X-Accel-Buffering' => 'no'
        ]);
    }

    /**
     * Endpoint para eliminar físicamente un archivo de respaldo.
     * DELETE /api/internal/backup
     */
    public function deleteFile(Request $request)
    {
        $token = config('backups.token');
        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');

        // 1. Validar expiración (máximo 5 minutos)
        if (abs(time() - (int)$timestamp) > 300) {
            return response()->json(['error' => 'Petición expirada.'], 403);
        }

        // 2. Validar firma HMAC-SHA256
        $payload = json_encode([
            'file' => $request->input('file'),
            'timestamp' => (int)$timestamp
        ]);

        $expectedSignature = hash_hmac('sha256', $timestamp . $payload, $token);

        if (!hash_equals($expectedSignature, (string)$signature)) {
            Log::error("Backup Hija: Intento de borrado con firma incorrecta.");
            return response()->json(['error' => 'No autorizado. Firma no coincide.'], 401);
        }

        $filename = $request->input('file');
        $filePath = storage_path('app/backups') . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($filePath)) {
            @unlink($filePath);
            Log::info("Backup Hija: Archivo {$filename} eliminado a petición de la Madre.");
            return response()->json([
                'status' => 'success',
                'message' => 'Archivo eliminado correctamente.'
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'El archivo no existe en el servidor de la hija.'
        ], 404);
    }
}
