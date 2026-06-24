<?php

namespace App\Http\Controllers\Cajas;

use App\Http\Controllers\Controller;
use App\Models\Denominacion;
use Illuminate\Http\Request;

class DenominacionController extends Controller
{
    public function index()
    {
        // Retornamos todas ordenadas por valor de mayor a menor (ideal para las vistas de arqueo)
        return response()->json(Denominacion::orderBy('valor', 'desc')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'valor' => 'required|numeric|min:0.01',
            'tipo' => 'required|in:billete,moneda',
            'activo' => 'boolean',
        ]);

        $denominacion = Denominacion::create($validated);
        return response()->json($denominacion, 201);
    }

    public function show(Denominacion $denominacion)
    {
        return response()->json($denominacion);
    }

    public function update(Request $request, Denominacion $denominacion)
    {
        $validated = $request->validate([
            'valor' => 'sometimes|required|numeric|min:0.01',
            'tipo' => 'sometimes|required|in:billete,moneda',
            'activo' => 'boolean',
        ]);

        $denominacion->update($validated);
        return response()->json($denominacion);
    }

    public function destroy(Denominacion $denominacion)
    {
        // En lugar de borrar (que podría romper reportes pasados), aplicamos un soft delete lógico
        $denominacion->update(['activo' => false]);
        return response()->json(['message' => 'Denominación desactivada correctamente.']);
    }
}
