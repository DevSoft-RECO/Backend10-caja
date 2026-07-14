<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\DescuadreAgencia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DescuadreAgenciaController extends Controller
{
    private function formatAdjuntos(DescuadreAgencia $descuadre)
    {
        $adjuntos = $descuadre->archivos_adjuntos;
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
        
        $query = DescuadreAgencia::with(['agencia', 'creador']);

        // Filtrar por Agencia si el usuario no es Super Admin
        if ($user && !in_array('Super Admin', $user->roles_list ?? [])) {
            $query->where('agencia_id', $user->id_agencia);
        }

        $query->orderBy('created_at', 'desc');

        $descuadres = $query->get()->map(function ($desc) {
            $desc->archivos_adjuntos = $this->formatAdjuntos($desc);
            return $desc;
        });

        return response()->json($descuadres);
    }

    public function store(Request $request)
    {
        $request->validate([
            'codigo_caja' => 'required|string|max:255',
            'nombre_receptor' => 'required|string|max:255',
            'tipo_descuadre' => 'required|in:FALTANTE,SOBRANTE',
            'monto_descuadre' => 'required|numeric|min:0',
            'descuadre_declarado' => 'required|in:SI,NO',
            'solucion' => 'nullable|string',
            'fecha_descuadre' => 'required|date',
            'comentario' => 'nullable|string',
            'archivos' => 'nullable|array',
            'archivos.*' => 'required|file|mimes:pdf|max:5120', // Solo PDF y Max 5MB por archivo
        ]);

        $user = auth()->user();
        $agenciaId = $user ? $user->id_agencia : null;
        $creadorId = $user ? $user->id : 1;

        if (!$agenciaId) {
            $agenciaId = \App\Models\Agencia::value('id') ?? 1;
        }

        // 1. Crear el descuadre preliminarmente
        $descuadre = DescuadreAgencia::create([
            'agencia_id' => $agenciaId,
            'usuario_creador_id' => $creadorId,
            'codigo_caja' => $request->codigo_caja,
            'nombre_receptor' => $request->nombre_receptor,
            'tipo_descuadre' => $request->tipo_descuadre,
            'monto_descuadre' => $request->monto_descuadre,
            'descuadre_declarado' => $request->descuadre_declarado,
            'solucion' => $request->solucion,
            'fecha_descuadre' => $request->fecha_descuadre,
            'comentario' => $request->comentario,
            'archivos_adjuntos' => []
        ]);

        // 2. Procesar archivos adjuntos utilizando el ID de la solicitud creada
        $adjuntos = [];
        $hasGcs = config('filesystems.disks.gcs.key_file') !== null;
        $disk = $hasGcs ? 'gcs' : 'public';
        $folder = $hasGcs ? 'APP_Tesoreria/Descaudres' : 'descuadres';

        if ($request->hasFile('archivos')) {
            $cleanCaja = str_replace(' ', '', strtolower($request->codigo_caja));
            
            foreach ($request->file('archivos') as $file) {
                $originalName = $file->getClientOriginalName();
                $extension = $file->getClientOriginalExtension();
                
                // Extraer el nombre del archivo sin la extensión y remover espacios en blanco
                $filenameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
                $cleanFilenamePart = str_replace(' ', '', $filenameWithoutExt);
                
                // Construir el nombre: descuadre_caja1_7_elnombre.pdf
                $filename = "descuadre_{$cleanCaja}_{$agenciaId}_{$cleanFilenamePart}.{$extension}";
                
                // Guardar con el nombre estructurado
                $path = $file->storeAs($folder, $filename, $disk);
                
                $adjuntos[] = [
                    'nombre' => $originalName,
                    'path' => $path,
                ];
            }

            // 3. Actualizar el descuadre con los adjuntos guardados
            $descuadre->update([
                'archivos_adjuntos' => $adjuntos
            ]);
        }

        $descuadre->load(['agencia', 'creador']);
        $descuadre->archivos_adjuntos = $this->formatAdjuntos($descuadre);

        return response()->json([
            'message' => 'Reporte de descuadre registrado con éxito.',
            'descuadre' => $descuadre
        ], 201);
    }
}
