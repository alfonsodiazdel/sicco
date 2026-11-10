<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoProyecto;
use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardRequest;
use App\Http\Requests\Reportes\ReporteEatRequest;
use App\Http\Resources\AlertaResource;
use App\Models\Alerta;
use App\Models\Proyecto;
use App\Queries\DashboardQuery;
use Illuminate\Http\JsonResponse;

/**
 * CU17 Visualizar dashboard gerencial: los proyectos activos asignados al usuario (puede haber varios
 * gerentes, cada uno con sus obras); el administrador los consulta todos. Solo lectura.
 */
class DashboardController extends Controller
{
    public const SIN_PROYECTOS = 'No hay proyectos activos';

    private const ALERTAS_RECIENTES = 10;

    public function show(DashboardRequest $request): JsonResponse
    {
        $usuario = $request->user();
        $proyectos = Proyecto::query()
            ->where('estado', EstadoProyecto::Activo)
            ->unless($usuario->tieneRol(Rol::Administrador),
                fn ($q) => $q->whereHas('usuarios', fn ($u) => $u->whereKey($usuario->id)))
            ->orderBy('codigo')
            ->get();

        $resultado = (new DashboardQuery($proyectos, $request->fechaInicio(), $request->fechaCierre()))->calcular();

        // Las alertas abiertas son las mismas que cuenta la campana del encabezado.
        $pendientes = Alerta::visiblesPara($usuario)->pendientes();

        return response()->json([
            'periodo' => [
                'desde' => $request->desde(),
                'hasta' => $request->hasta(),
                'fecha_inicio' => $request->fechaInicio(),
                'fecha_cierre' => $request->fechaCierre(),
                // El mes en curso aún no cierra: las cifras pueden cambiar.
                'parcial' => $request->fechaCierre() > today(ReporteEatRequest::ZONA_HORARIA)->toDateString(),
            ],
            ...$resultado,
            'alertas' => [
                'pendientes' => (clone $pendientes)->count(),
                'recientes' => AlertaResource::collection((clone $pendientes)
                    ->with(['proyecto', 'sector.nivel', 'sector.partida.resistencia', 'revisor'])
                    ->orderByDesc('fecha')->orderByDesc('id')
                    ->limit(self::ALERTAS_RECIENTES)
                    ->get())->resolve($request),
            ],
            'mensaje' => $proyectos->isEmpty() ? self::SIN_PROYECTOS : null,
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }
}
