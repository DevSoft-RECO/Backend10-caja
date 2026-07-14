<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\SolicitudReversion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SolicitudReversionController extends Controller
{
    private function formatAdjuntos(SolicitudReversion $solicitud)
    {
        $adjuntos = $solicitud->archivos_adjuntos;
        if (!is_array($adjuntos)) {
            return $adjuntos;
        }

        $hasGcs = config('filesystems.disks.gcs.key_file') !== null;

        foreach ($adjuntos as &$adjunto) {
            if (isset($adjunto['path'])) {
                if ($hasGcs) {
                    try {
                        $adjunto['url'] = Storage::disk('gcs')->temporaryUrl($adjunto['path'], now()->addSeconds(30));
                    } catch (\Exception $e) {
                        $adjunto['url'] = asset('storage/' . $adjunto['path']);
                    }
                } else {
                    $adjunto['url'] = asset('storage/' . $adjunto['path']);
                }
            }
        }
        return $adjuntos;
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        
        $query = SolicitudReversion::with(['agencia', 'creador', 'autorizador']);

        // Filtrar por Agencia si el usuario no es Super Admin
        if ($user && !in_array('Super Admin', $user->roles_list ?? [])) {
            $query->where('agencia_id', $user->id_agencia);
        }

        // Filtro por estado
        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        $query->orderBy('created_at', 'desc');

        $solicitudes = $query->get()->map(function ($sol) {
            $sol->archivos_adjuntos = $this->formatAdjuntos($sol);
            return $sol;
        });

        return response()->json($solicitudes);
    }

    public function store(Request $request)
    {
        $request->validate([
            'codigo_caja' => 'required|string|max:255',
            'nombre_cajero' => 'required|string|max:255',
            'codigo_transaccion' => 'required|string|max:255',
            'tipo_transaccion' => 'required|string|max:255',
            'motivo_reversion' => 'required|string',
            'archivos' => 'nullable|array',
            'archivos.*' => 'required|file|mimes:pdf|max:5120', // Solo PDF y Max 5MB por archivo
        ]);

        $user = auth()->user();
        $agenciaId = $user ? $user->id_agencia : null;
        $creadorId = $user ? $user->id : 1;

        if (!$agenciaId) {
            $agenciaId = \App\Models\Agencia::value('id') ?? 1;
        }

        // 1. Crear la solicitud preliminarmente
        $solicitud = SolicitudReversion::create([
            'agencia_id' => $agenciaId,
            'usuario_creador_id' => $creadorId,
            'codigo_caja' => $request->codigo_caja,
            'nombre_cajero' => $request->nombre_cajero,
            'codigo_transaccion' => $request->codigo_transaccion,
            'tipo_transaccion' => $request->tipo_transaccion,
            'motivo_reversion' => $request->motivo_reversion,
            'archivos_adjuntos' => [],
            'estado' => 'pendiente'
        ]);

        // 2. Procesar archivos adjuntos utilizando el ID de la solicitud creada
        $adjuntos = [];
        $hasGcs = config('filesystems.disks.gcs.key_file') !== null;
        $disk = $hasGcs ? 'gcs' : 'public';
        $folder = $hasGcs ? 'APP_Tesoreria/Solicitudes' : 'reversiones';

        if ($request->hasFile('archivos')) {
            $cleanCaja = str_replace(' ', '', strtolower($request->codigo_caja));
            
            foreach ($request->file('archivos') as $file) {
                $originalName = $file->getClientOriginalName();
                $extension = $file->getClientOriginalExtension();
                
                // Extraer el nombre del archivo sin la extensión y remover espacios en blanco
                $filenameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
                $cleanFilenamePart = str_replace(' ', '', $filenameWithoutExt);
                
                // Construir el nombre: solicitud_caja1_7_elnombre.pdf
                $filename = "solicitud_{$cleanCaja}_{$agenciaId}_{$cleanFilenamePart}.{$extension}";
                
                // Guardar con el nombre estructurado
                $path = $file->storeAs($folder, $filename, $disk);
                
                $adjuntos[] = [
                    'nombre' => $originalName,
                    'path' => $path,
                ];
            }

            // 3. Actualizar la solicitud con los adjuntos guardados
            $solicitud->update([
                'archivos_adjuntos' => $adjuntos
            ]);
        }

        $solicitud->load(['agencia', 'creador']);
        $solicitud->archivos_adjuntos = $this->formatAdjuntos($solicitud);

        return response()->json([
            'message' => 'Solicitud de reversión de caja registrada con éxito.',
            'solicitud' => $solicitud
        ], 201);
    }

    public function procesar(Request $request, $id)
    {
        $request->validate([
            'accion' => 'required|in:aprobado,rechazado',
            'observaciones' => 'nullable|string'
        ]);

        $solicitud = SolicitudReversion::findOrFail($id);

        if ($solicitud->estado !== 'pendiente') {
            return response()->json(['message' => 'Esta solicitud ya ha sido procesada anteriormente.'], 400);
        }

        $autorizadorId = auth()->id() ?? 1;

        $solicitud->update([
            'estado' => $request->accion,
            'usuario_autorizador_id' => $autorizadorId,
            'observaciones_autorizador' => $request->observaciones,
            'fecha_autorizacion' => now()
        ]);

        $solicitud->load(['agencia', 'creador', 'autorizador']);
        $solicitud->archivos_adjuntos = $this->formatAdjuntos($solicitud);

        return response()->json([
            'message' => 'Solicitud de reversión procesada correctamente.',
            'solicitud' => $solicitud
        ]);
    }

    public function destroy($id)
    {
        $solicitud = SolicitudReversion::findOrFail($id);

        if ($solicitud->estado === 'rechazado') {
            return response()->json(['message' => 'No se pueden eliminar solicitudes que han sido rechazadas.'], 400);
        }

        // Eliminar archivos adjuntos de GCS o almacenamiento local
        $adjuntos = $solicitud->archivos_adjuntos;
        if (is_array($adjuntos)) {
            $hasGcs = config('filesystems.disks.gcs.key_file') !== null;
            $disk = $hasGcs ? 'gcs' : 'public';

            foreach ($adjuntos as $adjunto) {
                if (isset($adjunto['path'])) {
                    Storage::disk($disk)->delete($adjunto['path']);
                }
            }
        }

        $solicitud->delete();

        return response()->json(['message' => 'Solicitud de reversión eliminada con éxito.']);
    }
}
