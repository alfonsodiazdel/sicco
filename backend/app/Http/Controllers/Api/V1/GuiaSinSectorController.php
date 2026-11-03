<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoAsignacion;
use App\Exports\GuiasSinSectorExport;
use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guias\GuiasSinSectorRequest;
use App\Http\Resources\GuiaPendienteResource;
use App\Models\GuiaRemision;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CU10 Consultar guías sin sector asignado: las que aún tienen volumen por asignar (sin sector o
 * asignadas en parte), de la más antigua a la más reciente para atender primero las atrasadas.
 */
class GuiaSinSectorController extends Controller
{
    use AutorizaProyecto;

    public function index(GuiasSinSectorRequest $request, Proyecto $proyecto): AnonymousResourceCollection
    {
        $this->autorizarProyecto($request, $proyecto);

        return GuiaPendienteResource::collection($this->pendientes($request, $proyecto));
    }

    /** CU10 requerimiento especial: la lista en Excel, con los mismos filtros. */
    public function exportar(GuiasSinSectorRequest $request, Proyecto $proyecto): StreamedResponse
    {
        $this->autorizarProyecto($request, $proyecto);

        return GuiasSinSectorExport::descargar(
            $proyecto,
            $this->pendientes($request, $proyecto),
            "guias-sin-sector-{$proyecto->codigo}-".today()->format('Ymd').'.xlsx',
        );
    }

    /** @return Collection<int, GuiaRemision> */
    private function pendientes(GuiasSinSectorRequest $request, Proyecto $proyecto): Collection
    {
        // Sin paginar: son las guías por atender, una lista corta mientras se asignen a tiempo.
        return $proyecto->guias()
            ->where('estado_asignacion', '!=', EstadoAsignacion::Asignada)
            ->when($request->validated('desde'), fn ($q, $desde) => $q->whereDate('fecha', '>=', $desde))
            ->when($request->validated('hasta'), fn ($q, $hasta) => $q->whereDate('fecha', '<=', $hasta))
            ->with(['proveedor', 'resistencia', 'producto'])
            ->withSum('asignaciones', 'volumen_m3')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();
    }
}
