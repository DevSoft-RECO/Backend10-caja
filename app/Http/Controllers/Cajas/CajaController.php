<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use Illuminate\Http\Request;

class CajaController extends Controller
{
    public function index(Request $request)
    {
        // Permitimos filtrar por agencia si viene en el request (muy útil para el dashboard)
        $query = Caja::with(['agencia', 'usuarioEnTurno']);

        if ($request->has('agencia_id')) {
            $query->where('agencia_id', $request->agencia_id);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'agencia_id' => 'required|exists:agencias,id',
            'nombre' => 'required|string|max:255',
            'tipo_caja' => 'required|in:boveda,general,ventanilla',
            'estado' => 'boolean',
        ]);

        $caja = Caja::create($validated);
        return response()->json($caja, 201);
    }

    public function show(Caja $caja)
    {
        return response()->json($caja->load(['agencia', 'usuarioEnTurno']));
    }

    public function update(Request $request, Caja $caja)
    {
        $validated = $request->validate([
            'agencia_id' => 'sometimes|required|exists:agencias,id',
            'nombre' => 'sometimes|required|string|max:255',
            'tipo_caja' => 'sometimes|required|in:boveda,general,ventanilla',
            'estado' => 'boolean',
        ]);

        $caja->update($validated);
        return response()->json($caja);
    }

    // Método extra para asignar un usuario a la caja (El "Login" a la ventanilla)
    public function asignarUsuario(Request $request, Caja $caja)
    {
        $validated = $request->validate([
            'usuario_id' => 'nullable|exists:users,id',
        ]);

        $caja->update(['usuario_id' => $validated['usuario_id']]);
        return response()->json([
            'message' => 'Usuario asignado correctamente',
            'caja' => $caja->load(['agencia', 'usuarioEnTurno'])
        ]);
    }
}
