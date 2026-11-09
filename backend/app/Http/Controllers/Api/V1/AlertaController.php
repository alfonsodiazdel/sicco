<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoAlerta;
use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Alertas\ListarAlertasRequest;
use App\Http\Resources\AlertaResource;
use App\Models\Alerta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** CU14 Recibir alerta de consumo: las alertas de los proyectos del usuario. */
class AlertaController extends Controller
{
    use AutorizaProyecto;

    private const RELACIONES = ['proyecto', 'sector.nivel', 'sector.partida.resistencia', 'revisor'];

    /** Pantalla «Alertas de consumo»: de la más reciente a la más antigua. */
    public function index(ListarAlertasRequest $request): AnonymousResourceCollection
    {
        $alertas = Alerta::visiblesPara($request->user())
            ->with(self::RELACIONES)
            ->when($request->validated('estado'), fn ($q, string $estado) => $q->where('estado', $estado))
            ->when($request->validated('proyecto_id'), fn ($q, $id) => $q->where('proyecto_id', $id))
            ->orderByDesc('fecha')->orderByDesc('id')
            ->limit(500)
            ->get();

        return AlertaResource::collection($alertas);
    }

    /** CU14 paso 4: el ícono de notificaciones muestra cuántas siguen pendientes. */
    public function resumen(Request $request): JsonResponse
    {
        return response()->json([
            'pendientes' => Alerta::visiblesPara($request->user())->pendientes()->count(),
        ]);
    }

    /** CU14 paso 5: el usuario marca la alerta como «Revisada». */
    public function revisar(Request $request, Alerta $alerta): JsonResponse
    {
        $this->autorizarProyecto($request, $alerta->proyecto);

        // Dos usuarios a la vez: solo el primero la marca.
        $marcada = DB::transaction(function () use ($request, $alerta) {
            $alerta = Alerta::whereKey($alerta->id)->lockForUpdate()->firstOrFail();
            if ($alerta->estado === EstadoAlerta::Revisada) {
                return false;
            }
            $alerta->update([
                'estado' => EstadoAlerta::Revisada,
                'revisada_por' => $request->user()->id,
                'revisada_at' => now(),
            ]);

            return true;
        });

        if (! $marcada) {
            return response()->json([
                'message' => __('sicco.alertas.ya_revisada'),
                'code' => 'alerta_revisada',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'alerta' => AlertaResource::make($alerta->refresh()->load(self::RELACIONES)),
            'message' => __('sicco.alertas.revisada'),
        ]);
    }
}
