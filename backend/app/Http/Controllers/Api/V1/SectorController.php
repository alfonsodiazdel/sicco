<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\CulminarSectorRequest;
use App\Http\Requests\SectorRequest;
use App\Http\Resources\SectorResource;
use App\Models\Alerta;
use App\Models\Asignacion;
use App\Models\HistorialAsignacion;
use App\Models\Nivel;
use App\Models\Partida;
use App\Models\Proyecto;
use App\Models\Sector;
use App\Support\Volumen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU05 Gestionar niveles y sectores de vaciado: los sectores de cada nivel. */
class SectorController extends Controller
{
    use AutorizaProyecto;

    /** Los de todos los niveles del proyecto: el disponible de una partida los suma a todos. */
    public function index(Request $request, Proyecto $proyecto): AnonymousResourceCollection
    {
        $this->autorizarProyecto($request, $proyecto);

        $sectores = Sector::whereIn('nivel_id', $proyecto->niveles()->select('id'))->orderBy('id')->get();

        return SectorResource::collection($sectores);
    }

    public function store(SectorRequest $request, Nivel $nivel): JsonResponse
    {
        $this->autorizarProyecto($request, $nivel->proyecto);

        $sector = $this->guardar($request, fn () => $nivel->sectores()->create($request->validated()));

        return SectorResource::make($sector->refresh())->response()->setStatusCode(201);
    }

    public function update(SectorRequest $request, Sector $sector): SectorResource
    {
        $this->autorizarProyecto($request, Nivel::findOrFail($sector->nivel_id)->proyecto);

        // Su volumen presupuestado ya es la base del costo real del reporte EAT (CU15).
        if ($sector->culminado()) {
            throw ValidationException::withMessages(['sector' => __('sicco.sectores.culminado')]);
        }

        $this->guardar($request, fn () => tap($sector)->update($request->validated()), $sector);

        return SectorResource::make($sector);
    }

    /** CU05: el sector ya no recibe concreto. Lo que se le vació pasa a ser costo real en el EAT. */
    public function culminar(CulminarSectorRequest $request, Sector $sector): JsonResponse
    {
        // El acceso al proyecto lo revisa CulminarSectorRequest antes de validar.
        $actualizado = DB::transaction(function () use ($request, $sector) {
            $sector = Sector::whereKey($sector->id)->lockForUpdate()->firstOrFail();
            if ($sector->culminado()) {
                throw ValidationException::withMessages(['sector' => __('sicco.sectores.ya_culminado')]);
            }
            $sector->forceFill([
                'fecha_culminacion' => $request->validated('fecha_culminacion'),
                'culminado_por' => $request->user()->id,
            ])->save();

            return $sector;
        });

        return SectorResource::make($actualizado)->additional(['message' => __('sicco.sectores.culminado_ok')])->response();
    }

    /** CU05: para corregir sus guías o su volumen; vuelve a proyectarse en el EAT. */
    public function reabrir(Request $request, Sector $sector): JsonResponse
    {
        $this->autorizarProyecto($request, Nivel::findOrFail($sector->nivel_id)->proyecto);

        $actualizado = DB::transaction(function () use ($sector) {
            $sector = Sector::whereKey($sector->id)->lockForUpdate()->firstOrFail();
            if (! $sector->culminado()) {
                throw ValidationException::withMessages(['sector' => __('sicco.sectores.no_culminado')]);
            }
            $sector->forceFill(['fecha_culminacion' => null, 'culminado_por' => null])->save();

            return $sector;
        });

        return SectorResource::make($actualizado)->additional(['message' => __('sicco.sectores.reabierto_ok')])->response();
    }

    /**
     * CU05: corrige un error de planificación. Solo sin guías asignadas ahora (si las tuvo, se
     * reasignan antes a otro sector) y sin culminar. Su volumen vuelve al disponible de la partida.
     */
    public function destroy(Request $request, Sector $sector): JsonResponse
    {
        $this->autorizarProyecto($request, Nivel::findOrFail($sector->nivel_id)->proyecto);

        DB::transaction(function () use ($sector) {
            // La asignación de guías bloquea el mismo sector: no se cruza con una que lo esté usando.
            $sector = Sector::whereKey($sector->id)->with('nivel')->lockForUpdate()->firstOrFail();
            if ($sector->culminado()) {
                throw ValidationException::withMessages(['sector' => __('sicco.sectores.culminado_no_eliminable')]);
            }
            if (Asignacion::where('sector_id', $sector->id)->exists()) {
                throw ValidationException::withMessages(['sector' => __('sicco.sectores.con_guias')]);
            }

            // Las guías que tuvo dejaron su id en el historial (CU09): se le agrega el nombre para
            // que el historial se siga leyendo sin el sector.
            $etiqueta = "{$sector->nivel->nombre} – {$sector->nombre}";
            HistorialAsignacion::where(fn ($q) => $q
                ->whereJsonContains('antes', [['sector_id' => $sector->id]])
                ->orWhereJsonContains('despues', [['sector_id' => $sector->id]]))
                ->lockForUpdate()->get()
                ->each(function (HistorialAsignacion $h) use ($sector, $etiqueta) {
                    $nombrar = fn (array $reparto) => array_map(
                        fn (array $a) => $a['sector_id'] === $sector->id ? $a + ['sector' => $etiqueta] : $a,
                        $reparto,
                    );
                    $h->forceFill(['antes' => $nombrar($h->antes), 'despues' => $nombrar($h->despues)])->save();
                });

            // Sus alertas (CU14) venían de guías que ya no están en él.
            Alerta::where('sector_id', $sector->id)->delete();
            $sector->delete();
        });

        return response()->json(['message' => __('sicco.sectores.eliminado')]);
    }

    /**
     * El volumen del sector no puede superar el disponible de su partida: metrado neto menos lo
     * repartido en los sectores de todos los niveles. Sin desperdicio: CU14 lo aplica después sobre
     * el volumen del sector. La partida queda bloqueada para que dos sectores a la vez no la excedan.
     */
    private function guardar(SectorRequest $request, callable $persistir, ?Sector $actual = null): Sector
    {
        return DB::transaction(function () use ($request, $persistir, $actual) {
            $partida = Partida::whereKey($request->validated('presupuesto_id'))->lockForUpdate()->firstOrFail();

            $repartido = $partida->sectores()
                ->when($actual, fn ($q) => $q->whereKeyNot($actual->id))
                ->sum('volumen_presup_m3');
            $disponible = Volumen::centesimos($partida->metrado_m3) - Volumen::centesimos($repartido);

            if (Volumen::centesimos($request->validated('volumen_presup_m3')) > $disponible) {
                throw ValidationException::withMessages(['volumen_presup_m3' => __('sicco.sectores.excede')]);
            }

            return $persistir();
        });
    }
}
