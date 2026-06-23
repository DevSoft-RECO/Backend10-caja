<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\Agencia;
use Illuminate\Http\JsonResponse;

class SSOController extends Controller
{
    /**
     * Sincroniza el perfil JIT (Just-In-Time) con la App Madre.
     * Esta función es el corazón del ecosistema para obtener identidad, roles y permisos.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        $motherUrl = config('services.mother_app.url');

        try {
            // 1. Consultar a la Madre usando el mismo Bearer Token
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/json'])
                ->get("{$motherUrl}/api/me");

            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Fallo en la sincronización con el ecosistema (Madre)',
                    'error' => $response->reason()
                ], 502);
            }

            $userData = $response->json();
            
            // Desempaquetar si viene en 'data' (Laravel Resources)
            if (isset($userData['data'])) {
                $userData = $userData['data'];
            }

            $username = $userData['username'] ?? 'unknown';

            // 2. APLANAMIENTO Y FILTRADO POR CATEGORÍA
            $userData['roles'] = $this->flatten($userData['roles'] ?? $userData['roles_list'] ?? []);
            
            $permissionCategory = config('services.mother_app.permission_category');

            if ($permissionCategory && isset($userData['permissions_detailed']) && is_array($userData['permissions_detailed'])) {
                // Filtrar solo los permisos correspondientes a la categoría configurada para esta Hija
                $filteredPermissions = array_filter($userData['permissions_detailed'], function ($perm) use ($permissionCategory) {
                    return isset($perm['category']) && $perm['category'] === $permissionCategory;
                });
                
                // Extraer los nombres de los permisos filtrados y reindexar el array
                $userData['permisos'] = array_values(array_map(function ($perm) {
                    return $perm['name'] ?? '';
                }, $filteredPermissions));
            } else {
                // Fallback: Aplanar todos los permisos si no se configuró categoría de filtrado
                $rawPermisos = $userData['permisos'] ?? $userData['permissions'] ?? $userData['permissions_list'] ?? [];
                $userData['permisos'] = $this->flatten($rawPermisos);
            }
            
            // 3. Extracción de JTI del Token (para mirroring con Go)
            $jti = null;
            $tokenParts = explode('.', $token);
            if (count($tokenParts) === 3) {
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1])), true);
                $jti = $payload['jti'] ?? null;
            }

            // 4. SINCRONIZACIÓN JIT (Just-In-Time)
            
            // Upsert Agencia
            if (isset($userData['agencia'])) {
                $agData = $userData['agencia'];
                Agencia::updateOrCreate(
                    ['id' => $agData['id']],
                    [
                        'nombre'    => $agData['nombre'],
                        'codigo'    => $agData['codigo'] ?? null,
                        'codigot24' => $agData['codigot24'] ?? null,
                        'direccion' => $agData['direccion'] ?? null,
                    ]
                );
            }

            // Upsert User (Vincular a través de username de forma segura e insensible a mayúsculas)
            $user = User::whereRaw('LOWER(username) = ?', [strtolower($username)])->first();

            $updateData = [
                'sso_id'           => $userData['id'],
                'name'             => $userData['name'],
                'email'            => $userData['email'],
                'telefono'         => $userData['telefono'] ?? null,
                'id_agencia'       => $userData['idagencia'] ?? null,
                'puesto'           => $userData['puesto']['nombre'] ?? $userData['puesto'] ?? null,
                'avatar'           => $userData['avatar'] ?? null,
                'roles_list'       => $userData['roles'],
                'permissions_list' => $userData['permisos'],
                'jti'              => $jti,
            ];

            if ($user) {
                // Si existe localmente, actualizamos datos pero conservamos su ID local autoincremental
                $user->update($updateData);
            } else {
                // Si es un usuario nuevo, la DB asigna el ID local de forma autoincremental
                $updateData['username'] = strtoupper($username); // Estandarizar
                $user = User::create($updateData);
            }

            // 5. Fallbacks de estandarización para el Frontend
            $userData['roles_list'] = $userData['roles'];
            $userData['permissions'] = $userData['permisos'];
            $userData['_source'] = 'madre_sync';

            return response()->json($userData);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error interno de comunicación SSO',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Convierte colecciones de objetos de roles/permisos (Spatie) en arreglos de strings.
     * 
     * @param mixed $items
     * @return array
     */
    private function flatten($items): array
    {
        if (!is_array($items)) return [];

        return array_map(function ($item) {
            return is_array($item) ? ($item['name'] ?? $item) : $item;
        }, $items);
    }
}
