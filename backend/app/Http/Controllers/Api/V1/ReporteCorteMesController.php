<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ReporteCorteMesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reportes\ReporteCorteMesRequest;
use App\Http\Requests\Reportes\ReporteEatRequest;
use App\Models\Proyecto;
use App\Models\ReporteGenerado;
use App\Queries\ReporteCorteMesQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** CU16 Generar reporte de corte a fin de mes de un proyecto asignado (lo comprueba el request). */
class ReporteCorteMesController extends Controller
{
    public const SIN_MOVIMIENTOS = 'No hay movimientos en el mes seleccionado';

    public function show(ReporteCorteMesRequest $request, Proyecto $proyecto): JsonResponse
    {
        $reporte = $this->calcular($request, $proyecto);
        if ($reporte === null) {
            return response()->json(['data' => null, 'mensaje' => self::SIN_MOVIMIENTOS]);
        }

        $this->registrar($request, $proyecto, ReporteGenerado::FORMATO_PANTALLA);

        // Los montos salen siempre como decimales (3200.0), nunca como enteros.
        return response()->json(['data' => $reporte], options: JSON_PRESERVE_ZERO_FRACTION);
    }

    public function excel(ReporteCorteMesRequest $request, Proyecto $proyecto): Response
    {
        return $this->exportar($request, $proyecto, ReporteGenerado::FORMATO_EXCEL,
            fn (array $reporte) => ReporteCorteMesExport::excel($reporte));
    }

    public function pdf(ReporteCorteMesRequest $request, Proyecto $proyecto): Response
    {
        return $this->exportar($request, $proyecto, ReporteGenerado::FORMATO_PDF,
            fn (array $reporte) => Pdf::loadView('reportes.corte-mes', [
                'encabezado' => ReporteCorteMesExport::encabezado($reporte),
                'columnas' => ReporteCorteMesExport::COLUMNAS,
                'filas' => ReporteCorteMesExport::filas($reporte),
            ])->setPaper('a4', 'landscape')
                ->setOption([
                    // Sin subconjunto, dompdf incrusta DejaVu entera.
                    'isFontSubsettingEnabled' => true,
                    // Por defecto el chroot es todo el backend, .env incluido.
                    'chroot' => resource_path('views'),
                    'isJavascriptEnabled' => false,
                ])
                ->download(ReporteCorteMesExport::nombreArchivo($reporte, 'pdf')));
    }

    /** @param  \Closure(array): Response  $descargar arma la respuesta con el archivo ya generado */
    private function exportar(ReporteCorteMesRequest $request, Proyecto $proyecto, string $formato, \Closure $descargar): Response
    {
        $reporte = $this->calcular($request, $proyecto);
        if ($reporte === null) {
            return response()->json(['message' => self::SIN_MOVIMIENTOS], 422);
        }

        // Se registra solo la descarga que sí sale.
        $respuesta = $descargar($reporte);
        $this->registrar($request, $proyecto, $formato);

        return $respuesta;
    }

    private function calcular(ReporteCorteMesRequest $request, Proyecto $proyecto): ?array
    {
        $hoy = today(ReporteEatRequest::ZONA_HORARIA)->toDateString();

        return (new ReporteCorteMesQuery($proyecto, $request->anio(), $request->mes(), $hoy))->calcular();
    }

    private function registrar(ReporteCorteMesRequest $request, Proyecto $proyecto, string $formato): void
    {
        ReporteGenerado::create([
            'usuario_id' => $request->user()->id,
            'proyecto_id' => $proyecto->id,
            'tipo' => ReporteGenerado::TIPO_CORTE_MES,
            'parametros' => ['anio' => $request->anio(), 'mes' => $request->mes()],
            'formato' => $formato,
        ]);
    }
}
