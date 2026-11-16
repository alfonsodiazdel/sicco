<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ElementoEstructuralRequest;
use App\Http\Resources\ElementoEstructuralResource;
use App\Models\ElementoEstructural;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Catálogo de elementos estructurales de las partidas. */
class ElementoEstructuralController extends Controller
{
    /** Para los combos de partida, guía y factura: solo los activos. */
    public function index(): AnonymousResourceCollection
    {
        return ElementoEstructuralResource::collection(ElementoEstructural::with('grupos:grupos_partida.id')->where('activo', true)->orderBy('id')->get());
    }

    /** Para el mantenedor: todos, con su estado y si están en uso. */
    public function todos(): AnonymousResourceCollection
    {
        return ElementoEstructuralResource::collection(ElementoEstructural::with('grupos:grupos_partida.id')->orderBy('id')->get());
    }

    public function store(ElementoEstructuralRequest $request): JsonResponse
    {
        $elemento = DB::transaction(function () use ($request) {
            $elemento = ElementoEstructural::create($request->safe()->except('grupos'));
            $elemento->grupos()->sync($request->validated('grupos', []));

            return $elemento;
        });

        return ElementoEstructuralResource::make($elemento->refresh()->load('grupos:grupos_partida.id'))
            ->response()
            ->setStatusCode(201);
    }

    /** Un elemento en uso solo cambia de estado: renombrarlo dejaría las partidas con un nombre que ya no existe. */
    public function update(ElementoEstructuralRequest $request, ElementoEstructural $elemento): ElementoEstructuralResource|JsonResponse
    {
        if ($request->validated('nombre') !== $elemento->nombre && $elemento->enUso()) {
            return $this->enUso();
        }

        DB::transaction(function () use ($request, $elemento) {
            $elemento->update($request->safe()->except('grupos'));
            if ($request->has('grupos')) {
                $elemento->grupos()->sync($request->validated('grupos'));
            }
        });

        return ElementoEstructuralResource::make($elemento->load('grupos:grupos_partida.id'));
    }

    public function destroy(ElementoEstructural $elemento): Response|JsonResponse
    {
        if ($elemento->enUso()) {
            return $this->enUso();
        }

        $elemento->delete();

        return response()->noContent();
    }

    private function enUso(): JsonResponse
    {
        return response()->json([
            'message' => __('sicco.elementos.en_uso'),
            'code' => 'elemento_en_uso',
        ], Response::HTTP_CONFLICT);
    }
}
