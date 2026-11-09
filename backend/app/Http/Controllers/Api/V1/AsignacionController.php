<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoAsignacion;
use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guias\AsignarGuiaRequest;
use App\Http\Resources\AsignacionResource;
use App\Http\Resources\GuiaPendienteResource;
use App\Models\Asignacion;
use App\Models\GuiaRemision;
use App\Models\HistorialAsignacion;
use App\Models\Sector;
use App\Services\Desperdicio\CalculadoraDesperdicio;
use App\Support\Volumen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU09 Asignar guía a sector de vaciado. */
class AsignacionController extends Controller
{
    use AutorizaProyecto;

    /** CU09 paso 1: la guía con su volumen disponible y su reparto actual. */
    public function show(Request $request, GuiaRemision $guia): JsonResponse
    {
        $this->autorizarProyecto($request, $guia->proyecto);

        return $this->respuesta($guia);
    }

    /**
     * CU09 pasos 5–7: guarda el reparto, ajusta el volumen vaciado de cada sector tocado y el estado
     * de la guía, y deja el historial. La guía y los sectores quedan bloqueados para que dos
     * guardados a la vez no descuadren el vaciado.
     */
    public function update(AsignarGuiaRequest $request, GuiaRemision $guia): JsonResponse
    {
        // El acceso al proyecto lo revisa AsignarGuiaRequest antes de validar.
        $nuevas = collect($request->validated('asignaciones'))
            ->mapWithKeys(fn (array $a) => [(int) $a['sector_id'] => Volumen::centesimos($a['volumen_m3'])]);

        DB::transaction(function () use ($request, $guia, $nuevas) {
            $guia = GuiaRemision::whereKey($guia->id)->lockForUpdate()->firstOrFail();

            // CU09 flujo alternativo 1.
            if ($nuevas->sum() > Volumen::centesimos($guia->volumen_m3)) {
                throw ValidationException::withMessages(['asignaciones' => __('sicco.asignaciones.excede_guia')]);
            }

            $anteriores = $guia->asignaciones()->get()->keyBy('sector_id');
            $centesimosAnteriores = $anteriores->map(fn (Asignacion $a) => Volumen::centesimos($a->volumen_m3));

            // En orden de id, para que dos transacciones no se bloqueen en cruz.
            $sectores = Sector::whereKey($nuevas->keys()->merge($anteriores->keys())->unique())
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($sectores as $sector) {
                $diferencia = $nuevas->get($sector->id, 0) - $centesimosAnteriores->get($sector->id, 0);
                if ($diferencia !== 0) {
                    $sector->forceFill([
                        'volumen_vaciado_m3' => (Volumen::centesimos($sector->volumen_vaciado_m3) + $diferencia) / 100,
                    ])->save();
                }
            }

            $guia->asignaciones()->whereNotIn('sector_id', $nuevas->keys())->delete();
            foreach ($nuevas as $sectorId => $centesimos) {
                $guia->asignaciones()->updateOrCreate(
                    ['sector_id' => $sectorId],
                    ['volumen_m3' => $centesimos / 100, 'registrado_por' => $request->user()->id],
                );
            }

            $guia->update([
                'estado_asignacion' => $nuevas->sum() === Volumen::centesimos($guia->volumen_m3)
                    ? EstadoAsignacion::Asignada
                    : EstadoAsignacion::Parcial,
            ]);

            HistorialAsignacion::create([
                'guia_id' => $guia->id,
                'usuario_id' => $request->user()->id,
                'antes' => self::reparto($centesimosAnteriores),
                'despues' => self::reparto($nuevas),
            ]);
        });

        return $this->respuesta($guia->refresh(), __('sicco.asignaciones.guardada'));
    }

    private function respuesta(GuiaRemision $guia, ?string $mensaje = null): JsonResponse
    {
        $guia->load(['proveedor', 'resistencia', 'producto'])->loadSum('asignaciones', 'volumen_m3');
        $asignaciones = $guia->asignaciones()->with('sector.partida')->orderBy('id')->get();

        return response()->json(array_filter([
            'guia' => GuiaPendienteResource::make($guia),
            'asignaciones' => AsignacionResource::collection($asignaciones),
            // CU09 flujo alternativo 2: sectores que ya pasan lo presupuestado más el desperdicio.
            // La alerta de CU14 se registra con ese caso de uso; por ahora la pantalla lo avisa.
            'sectores_excedidos' => $asignaciones->map->sector->filter(fn (Sector $s) => self::excede($s))
                ->map(fn (Sector $s) => ['id' => $s->id, 'nombre' => $s->nombre])->values(),
            'message' => $mensaje,
        ], fn ($valor) => $valor !== null));
    }

    /** Límite del sector (CU14), calculado igual que en el control de desperdicio (CU13). */
    private static function excede(Sector $sector): bool
    {
        return CalculadoraDesperdicio::excede($sector);
    }

    /** @param  Collection<int, int>  $centesimos  por sector */
    private static function reparto(Collection $centesimos): array
    {
        return $centesimos->map(fn (int $c, int $sectorId) => ['sector_id' => $sectorId, 'volumen_m3' => $c / 100])
            ->values()->all();
    }
}
