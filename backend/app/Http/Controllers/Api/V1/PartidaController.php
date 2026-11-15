<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\PartidaRequest;
use App\Http\Resources\PartidaResource;
use App\Models\Concreto;
use App\Models\Partida;
use App\Models\Proyecto;
use App\Support\Volumen;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU04 Registrar el presupuesto de concreto de un proyecto. */
class PartidaController extends Controller
{
    use AutorizaProyecto;

    public function index(Request $request, Proyecto $proyecto): AnonymousResourceCollection
    {
        $this->autorizarProyecto($request, $proyecto);

        return PartidaResource::collection($proyecto->partidas()->with('concreto.resistencia')->orderBy('id')->get());
    }

    public function store(PartidaRequest $request, Proyecto $proyecto): JsonResponse
    {
        $this->autorizarProyecto($request, $proyecto);
        $this->exigirConfirmacion($request);

        $partida = DB::transaction(function () use ($request, $proyecto) {
            $concreto = $this->fijarPrecio($request, $proyecto->id);

            return $proyecto->partidas()->create([...$request->datos(), 'concreto_id' => $concreto->id]);
        });

        return PartidaResource::make($partida->load('concreto.resistencia'))->response()->setStatusCode(201);
    }

    public function update(PartidaRequest $request, Partida $partida): PartidaResource
    {
        $this->autorizarProyecto($request, $partida->proyecto);
        $this->exigirConfirmacion($request);

        // Con la partida bloqueada, un sector nuevo no puede colarse entre la suma y el guardado.
        DB::transaction(function () use ($request, $partida) {
            Partida::whereKey($partida->id)->lockForUpdate()->first();

            // El metrado no puede quedar debajo de lo ya repartido: el disponible de CU05 quedaría negativo.
            $repartido = Volumen::centesimos($partida->sectores()->sum('volumen_presup_m3'));
            if (Volumen::centesimos($request->validated('metrado_m3')) < $repartido) {
                throw ValidationException::withMessages(['metrado_m3' => __('sicco.partidas.metrado_repartido', [
                    'repartido' => number_format($repartido / 100, 2),
                ])]);
            }

            $concreto = $this->fijarPrecio($request, $partida->proyecto_id, $partida);
            $partida->update([...$request->datos(), 'concreto_id' => $concreto->id]);
        });

        return PartidaResource::make($partida->load('concreto.resistencia'));
    }

    /**
     * El concreto del proyecto con la resistencia de la partida, con el precio enviado. Si otras
     * partidas usan ese concreto, el precio nuevo les llega a todas: se acepta solo si se confirma.
     */
    private function fijarPrecio(PartidaRequest $request, int $proyectoId, ?Partida $partida = null): Concreto
    {
        $resistenciaId = (int) $request->validated('resistencia_id');
        $precio = Volumen::centesimos($request->validated('precio_unitario'));

        $concreto = Concreto::where('proyecto_id', $proyectoId)
            ->where('resistencia_id', $resistenciaId)
            ->lockForUpdate()
            ->first();

        if ($concreto && Volumen::centesimos($concreto->precio_unitario) !== $precio && ! $request->boolean('confirmado_precio')) {
            $otras = $concreto->partidas()->when($partida, fn ($q) => $q->whereKeyNot($partida->id))->count();
            if ($otras > 0) {
                $mensaje = trans_choice('sicco.partidas.confirmar_precio', $otras, [
                    'cantidad' => $otras,
                    'resistencia' => $concreto->resistencia->nombre,
                    'anterior' => number_format((float) $concreto->precio_unitario, 2),
                ]);

                throw new HttpResponseException(response()->json([
                    'message' => $mensaje,
                    'code' => 'requiere_confirmacion_precio',
                    'errors' => ['precio_unitario' => [$mensaje]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY));
            }
        }

        return Concreto::fijar($proyectoId, $resistenciaId, $request->validated('precio_unitario'));
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
