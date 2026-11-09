<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reportes\ReporteEatRequest;
use App\Models\Proyecto;
use App\Models\ReporteGenerado;
use App\Queries\ReporteEatQuery;
use Illuminate\Http\JsonResponse;

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
}
