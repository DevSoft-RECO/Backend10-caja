<?php

namespace App\Console\Commands;

// ==========================================
// === BACKUP SYSTEM ===
// Comando para procesar el respaldo en segundo plano y notificar a la Madre
// ==========================================

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BackupWorkerCommand extends Command
{
    protected $signature = 'db:backup-worker 
                            {--file= : Nombre del archivo de respaldo} 
                            {--callback= : URL de callback de la Madre} 
                            {--user= : ID del usuario que solicitó el respaldo} 
                            {--app= : Identificador de la app hija}';

    protected $description = 'Procesa el volcado de base de datos en background y notifica a la APP_MADRE.';

    public function handle()
    {
        $filename = $this->option('file');
        $callbackUrl = $this->option('callback');
        $userId = $this->option('user');
        $appKey = $this->option('app');

        $dbName = config('database.connections.mysql.database');
        $dbUser = config('database.connections.mysql.username');
        $dbPass = config('database.connections.mysql.password');
        $dbHost = config('database.connections.mysql.host');
        $dbPort = config('database.connections.mysql.port', '3306');

        $backupDir = storage_path('app/backups');
        $filePath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        // Obtener la ruta del ejecutable mysqldump desde config
        $mysqldumpPath = config('backups.mysqldump_path') ?? config('backups.pg_dump_path') ?? 'mysqldump';

        // Construir comando de volcado
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $passOpt = $dbPass ? "-p\"{$dbPass}\"" : "";

        if ($isWindows) {
            // En Windows (Laragon / Desarrollo)
            // Hacemos dump directo (sin comprimir en gzip por si no está en el PATH)
            $command = "\"{$mysqldumpPath}\" -h {$dbHost} -P {$dbPort} -u {$dbUser} {$passOpt} {$dbName} > \"" . $filePath . "\"";
        } else {
            // En Linux (Producción)
            // Hacemos dump y lo pasamos por gzip
            $command = "\"{$mysqldumpPath}\" -h {$dbHost} -P {$dbPort} -u {$dbUser} {$passOpt} {$dbName} | gzip > \"" . $filePath . "\"";
        }

        Log::info("BackupWorker: Iniciando comando: {$command}");

        $output = [];
        $resultCode = null;
        exec($command, $output, $resultCode);

        // Verificar el resultado
        if ($resultCode === 0 && file_exists($filePath)) {
            Log::info("BackupWorker: Respaldo generado con éxito: {$filename}");
            $size = filesize($filePath);
            $this->sendCallback($callbackUrl, $appKey, $filename, 'success', $userId, null, $size);
        } else {
            $errorMsg = "Error al ejecutar mysqldump. Código de salida: {$resultCode}";
            Log::error("BackupWorker: " . $errorMsg);
            $this->sendCallback($callbackUrl, $appKey, $filename, 'failed', $userId, $errorMsg);
        }
    }

    private function sendCallback($url, $appKey, $filename, $status, $userId, $error = null, $size = null)
    {
        $token = config('backups.token');
        $timestamp = time();

        $payloadData = [
            'app_key' => $appKey,
            'file' => $filename,
            'status' => $status,
            'user_id' => (int)$userId,
            'timestamp' => $timestamp,
            'error' => $error
        ];

        if ($size !== null) {
            $payloadData['size'] = $size;
        }

        $payload = json_encode($payloadData);

        // Firmar la petición de retorno para que la Madre sepa que es auténtica
        $signature = hash_hmac('sha256', $timestamp . $payload, $token);

        try {
            Log::info("BackupWorker: Enviando callback a la Madre: {$url}");
            $response = Http::withHeaders([
                'X-Signature' => $signature,
                'X-Timestamp' => $timestamp,
                'Content-Type' => 'application/json'
            ])->post($url, json_decode($payload, true));

            Log::info("BackupWorker: Respuesta de la Madre: " . $response->status());
        } catch (\Exception $e) {
            Log::error("BackupWorker: Error al enviar callback: " . $e->getMessage());
        }
    }
}
