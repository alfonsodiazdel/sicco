<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrupoPartidaRequest;
use App\Http\Resources\GrupoPartidaResource;
use App\Models\GrupoPartida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Catálogo global de grupos de partidas del presupuesto. */
class GrupoPartidaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return GrupoPartidaResource::collection(GrupoPartida::orderBy('orden')->orderBy('id')->get());
    }

    public function store(GrupoPartidaRequest $request): JsonResponse
    {
        return GrupoPartidaResource::make(GrupoPartida::create($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(GrupoPartidaRequest $request, GrupoPartida $grupo): GrupoPartidaResource
    {
        $grupo->update($request->validated());

        return GrupoPartidaResource::make($grupo);
    }

    /** Un grupo con partidas en cualquier proyecto no se elimina: sus presupuestos quedarían sin grupo. */
    public function destroy(GrupoPartida $grupo): Response|JsonResponse
    {
        if ($grupo->partidas()->exists()) {
            return response()->json([
                'message' => __('sicco.grupos.en_uso'),
                'code' => 'grupo_en_uso',
            ], Response::HTTP_CONFLICT);
        }

        $grupo->delete();

        return response()->noContent();
    }
}
