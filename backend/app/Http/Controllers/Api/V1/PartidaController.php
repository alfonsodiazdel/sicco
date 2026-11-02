<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Requests\PartidaRequest;
use App\Http\Resources\PartidaResource;
use App\Models\Partida;
use App\Models\Proyecto;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** CU04 Registrar el presupuesto de concreto de un proyecto. */
class PartidaController extends Controller
{
    public function index(Request $request, Proyecto $proyecto): AnonymousResourceCollection
    {
        $this->autorizarProyecto($request, $proyecto);

        return PartidaResource::collection($proyecto->partidas()->orderBy('id')->get());
    }

    public function store(PartidaRequest $request, Proyecto $proyecto): JsonResponse
    {
        $this->autorizarProyecto($request, $proyecto);
        $this->exigirConfirmacion($request);

        $partida = $proyecto->partidas()->create($request->datos());

        return PartidaResource::make($partida)->response()->setStatusCode(201);
    }

    public function update(PartidaRequest $request, Partida $partida): PartidaResource
    {
        $this->autorizarProyecto($request, $partida->proyecto);
        $this->exigirConfirmacion($request);

        $partida->update($request->datos());

        return PartidaResource::make($partida);
    }

    /**
     * Solo los proyectos asignados al usuario (USUARIO_PROYECTO), como el selector del encabezado.
     * El administrador consulta todos.
     */
    private function autorizarProyecto(Request $request, Proyecto $proyecto): void
    {
        $usuario = $request->user();
        if ($usuario->tieneRol(Rol::Administrador)
            || $proyecto->usuarios()->whereKey($usuario->id)->exists()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => __('sicco.partidas.sin_acceso'),
            'code' => 'sin_permiso',
        ], Response::HTTP_FORBIDDEN));
    }

    /** Confirmación «blanda» de CU04: el desperdicio mayor a 15 % se acepta solo si se confirma. */
    private function exigirConfirmacion(PartidaRequest $request): void
    {
        $desperdicio = (float) $request->validated('factor_desperdicio');
        if ($desperdicio <= config('sicco.partidas.desperdicio_confirmar') || $request->boolean('confirmado')) {
            return;
        }

        $mensaje = __('sicco.partidas.confirmar_desperdicio', ['valor' => number_format($desperdicio, 2)]);

        throw new HttpResponseException(response()->json([
            'message' => $mensaje,
            'code' => 'requiere_confirmacion',
            'errors' => ['factor_desperdicio' => [$mensaje]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
