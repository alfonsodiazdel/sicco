<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Desperdicio\DesperdicioRequest;
use App\Models\Proyecto;
use App\Services\Desperdicio\CalculadoraDesperdicio;
use Illuminate\Http\JsonResponse;

/** CU13 Consultar control de desperdicio: solo lectura, de los proyectos asignados. */
class DesperdicioController extends Controller
{
    use AutorizaProyecto;

    public function show(DesperdicioRequest $request, Proyecto $proyecto, CalculadoraDesperdicio $calculadora): JsonResponse
    {
        $this->autorizarProyecto($request, $proyecto);

        return response()->json(
            $calculadora->calcular($proyecto, $request->validated('detalle') ?? 'sector'),
        );
    }
}
