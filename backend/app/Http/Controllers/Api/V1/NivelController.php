<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\NivelRequest;
use App\Http\Resources\NivelResource;
use App\Models\Nivel;
use App\Models\Proyecto;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU05 Gestionar niveles y sectores de vaciado: los niveles (pisos) del proyecto. */
class NivelController extends Controller
{
    use AutorizaProyecto;

    public function index(Request $request, Proyecto $proyecto): AnonymousResourceCollection
    {
        $this->autorizarProyecto($request, $proyecto);

        return NivelResource::collection($proyecto->niveles()->orderBy('orden')->orderBy('id')->get());
    }

    public function store(NivelRequest $request, Proyecto $proyecto): JsonResponse
    {
        $this->autorizarProyecto($request, $proyecto);

        return NivelResource::make($proyecto->niveles()->create($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(NivelRequest $request, Nivel $nivel): NivelResource
    {
        $this->autorizarProyecto($request, $nivel->proyecto);

        $nivel->update($request->validated());

        return NivelResource::make($nivel);
    }

    /** CU05: corrige un error de planificación. Solo un nivel sin sectores. */
    public function destroy(Request $request, Nivel $nivel): JsonResponse
    {
        $this->autorizarProyecto($request, $nivel->proyecto);

        try {
            DB::transaction(function () use ($nivel) {
                $nivel = Nivel::whereKey($nivel->id)->lockForUpdate()->firstOrFail();
                if ($nivel->sectores()->exists()) {
                    throw ValidationException::withMessages(['nivel' => __('sicco.niveles.con_sectores')]);
                }
                $nivel->delete();
            });
        } catch (QueryException $e) {
            // Un sector creado a la vez: la clave foránea no deja eliminar el nivel.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            throw ValidationException::withMessages(['nivel' => __('sicco.niveles.con_sectores')]);
        }

        return response()->json(['message' => __('sicco.niveles.eliminado')]);
    }
}
