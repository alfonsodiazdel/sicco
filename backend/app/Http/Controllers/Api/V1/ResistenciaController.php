<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResistenciaRequest;
use App\Http\Resources\ResistenciaMantenedorResource;
use App\Http\Resources\ResistenciaResource;
use App\Models\Resistencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Catálogo de resistencias f'c: selectores del presupuesto y de la guía, y su mantenedor. */
class ResistenciaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ResistenciaResource::collection(Resistencia::where('activo', true)->orderBy('fc_kg_cm2')->get());
    }

    /** Para el mantenedor: todas, con su estado y si están en uso. */
    public function todas(): AnonymousResourceCollection
    {
        return ResistenciaMantenedorResource::collection(Resistencia::orderBy('fc_kg_cm2')->get());
    }

    public function store(ResistenciaRequest $request): JsonResponse
    {
        return ResistenciaMantenedorResource::make(Resistencia::create($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    /** Una resistencia en uso solo cambia de estado: cambiar su f'c alteraría presupuestos, guías y facturas ya registrados. */
    public function update(ResistenciaRequest $request, Resistencia $resistencia): ResistenciaMantenedorResource|JsonResponse
    {
        $datos = $request->validated();

        if (($datos['fc_kg_cm2'] !== $resistencia->fc_kg_cm2 || $datos['nombre'] !== $resistencia->nombre) && $resistencia->enUso()) {
            return $this->enUso();
        }

        $resistencia->update($datos);

        return ResistenciaMantenedorResource::make($resistencia);
    }

    public function destroy(Resistencia $resistencia): Response|JsonResponse
    {
        if ($resistencia->enUso()) {
            return $this->enUso();
        }

        $resistencia->delete();

        return response()->noContent();
    }

    private function enUso(): JsonResponse
    {
        return response()->json([
            'message' => __('sicco.resistencias.en_uso'),
            'code' => 'resistencia_en_uso',
        ], Response::HTTP_CONFLICT);
    }
}
