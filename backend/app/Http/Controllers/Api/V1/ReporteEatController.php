<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ReporteEatExport;
use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reportes\ReporteEatRequest;
use App\Models\Proyecto;
use App\Models\ReporteGenerado;
use App\Queries\ReporteEatQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** CU15 Generar reporte EAT de un proyecto asignado, a una fecha de corte. */
class ReporteEatController extends Controller
{
    use AutorizaProyecto;

    public const SIN_INFORMACION = 'No hay información para la fecha seleccionada';

    public function show(ReporteEatRequest $request, Proyecto $proyecto): JsonResponse
    {
        $this->autorizarProyecto($request, $proyecto);
        $fechaCorte = $request->validated('fecha_corte');

        $reporte = (new ReporteEatQuery($proyecto, $fechaCorte))->calcular();
        if ($reporte === null) {
            return response()->json(['data' => null, 'mensaje' => self::SIN_INFORMACION]);
        }

        ReporteGenerado::create([
            'usuario_id' => $request->user()->id,
            'proyecto_id' => $proyecto->id,
            'tipo' => ReporteGenerado::TIPO_EAT,
            'parametros' => ['fecha_corte' => $fechaCorte],
            'formato' => ReporteGenerado::FORMATO_PANTALLA,
        ]);

        // Los montos salen siempre como decimales (3200.0), nunca como enteros.
        return response()->json(['data' => $reporte], options: JSON_PRESERVE_ZERO_FRACTION);
    }

    public function excel(ReporteEatRequest $request, Proyecto $proyecto): Response
    {
        return $this->exportar($request, $proyecto, ReporteGenerado::FORMATO_EXCEL,
            fn (array $reporte) => ReporteEatExport::excel($reporte));
    }

    public function pdf(ReporteEatRequest $request, Proyecto $proyecto): Response
    {
        return $this->exportar($request, $proyecto, ReporteGenerado::FORMATO_PDF,
            fn (array $reporte) => Pdf::loadView('reportes.eat', [
                'encabezado' => ReporteEatExport::encabezado($reporte),
                'columnas' => ReporteEatExport::COLUMNAS,
                'filas' => ReporteEatExport::filas($reporte),
            ])->setPaper('a4', 'landscape')
                // Sin subconjunto, dompdf incrusta DejaVu entera: ~880 KB por una página.
                ->setOption('isFontSubsettingEnabled', true)
                ->download(ReporteEatExport::nombreArchivo($reporte, 'pdf')));
    }

    /** @param  \Closure(array): Response  $descargar */
    private function exportar(ReporteEatRequest $request, Proyecto $proyecto, string $formato, \Closure $descargar): Response
    {
        $this->autorizarProyecto($request, $proyecto);
        $fechaCorte = $request->validated('fecha_corte');

        $reporte = (new ReporteEatQuery($proyecto, $fechaCorte))->calcular();
        if ($reporte === null) {
            return response()->json(['message' => self::SIN_INFORMACION], 422);
        }

        ReporteGenerado::create([
            'usuario_id' => $request->user()->id,
            'proyecto_id' => $proyecto->id,
            'tipo' => ReporteGenerado::TIPO_EAT,
            'parametros' => ['fecha_corte' => $fechaCorte],
            'formato' => $formato,
        ]);

        return $descargar($reporte);
    }
}
