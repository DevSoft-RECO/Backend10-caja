<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Auth;

class ValidateSSO
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Token requerido'], 401);
        }

        try {
            $publicKeyPath = storage_path('oauth-public.key');

            if (!file_exists($publicKeyPath)) {
                throw new \Exception("Falta llave pública en servidor hijo");
            }

            $publicKey = file_get_contents($publicKeyPath);
            JWT::$leeway = 60; // Margen de error para relojes desincronizados

            // Decodificar Token con RS256
            $decoded = JWT::decode($token, new Key($publicKey, 'RS256'));

            // Intentar cargar el usuario real de la base de datos local usando el ID de la Madre (sso_id)
            $dbUser = \App\Models\User::where('sso_id', $decoded->sub)->first();

            if ($dbUser) {
                Auth::setUser($dbUser);
            } else {
                // Si no existe localmente aún, usar el modelo User (no persistido) como respaldo temporal
                // Esto garantiza que métodos como hasRole() y hasPermission() existan.
                $user = new \App\Models\User([
                    'sso_id' => $decoded->sub,
                    'roles_list' => $decoded->roles ?? [],
                    'permissions_list' => $decoded->permissions ?? [],
                ]);
                // Forzar el ID temporal en el modelo para evitar nulos durante la misma request
                $user->id = $decoded->sub;
                Auth::setUser($user);
            }

        } catch (\Exception $e) {
            return response()->json(['message' => 'Acceso Denegado: ' . $e->getMessage()], 401);
        }

        return $next($request);
    }
}
