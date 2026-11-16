<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrupoPartidaRequest;
use App\Http\Resources\GrupoPartidaResource;
use App\Models\GrupoPartida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Catálogo global de grupos de partidas del presupuesto. */
class GrupoPartidaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return GrupoPartidaResource::collection(GrupoPartida::with('elementos:elementos_estructurales.id')->orderBy('orden')->orderBy('id')->get());
    }

    public function store(GrupoPartidaRequest $request): JsonResponse
    {
        $grupo = DB::transaction(function () use ($request) {
            $grupo = GrupoPartida::create($request->safe()->except('elementos'));
            $grupo->elementos()->sync($request->validated('elementos', []));

            return $grupo;
        });

        return GrupoPartidaResource::make($grupo->load('elementos:elementos_estructurales.id'))
            ->response()
            ->setStatusCode(201);
    }

    /** Los elementos se reemplazan por los enviados; el request ya impidió quitar uno en uso. */
    public function update(GrupoPartidaRequest $request, GrupoPartida $grupo): GrupoPartidaResource
    {
        DB::transaction(function () use ($request, $grupo) {
            $grupo->update($request->safe()->except('elementos'));
            if ($request->has('elementos')) {
                $grupo->elementos()->sync($request->validated('elementos'));
            }
        });

        return GrupoPartidaResource::make($grupo->load('elementos:elementos_estructurales.id'));
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
