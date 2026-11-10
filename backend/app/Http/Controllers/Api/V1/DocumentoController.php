<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documentos\ExportarDocumentosRequest;
use App\Models\Factura;
use App\Models\GuiaRemision;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Services\Documentos\ArmadorZip;
use Illuminate\Cache\Lock;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CU18 Exportar documentos: lista las guías o facturas del proyecto con filtros y descarga en un
 * ZIP sus fotos o archivos, para archivarlos o imprimirlos sin buscarlos uno por uno.
 */
class DocumentoController extends Controller
{
    /** Cada archivo se baja del disco en la misma petición; más documentos van en otro lote. */
    public const TOPE = 300;

    public function guias(ExportarDocumentosRequest $request, Proyecto $proyecto): JsonResponse
    {
        return response()->json(['data' => $this->consulta($request, $proyecto, 'guias')->get()
            ->map(fn (GuiaRemision $g) => [
                'id' => $g->id,
                'numero' => $g->numero,
                'fecha' => $g->fecha->toDateString(),
                'proveedor' => self::proveedor($g->proveedor),
                'volumen_m3' => (float) $g->volumen_m3,
                'estado_asignacion' => $g->estado_asignacion->value,
                'tiene_foto' => (bool) $g->foto_ruta,
            ])]);
    }

    public function facturas(ExportarDocumentosRequest $request, Proyecto $proyecto): JsonResponse
    {
        return response()->json(['data' => $this->consulta($request, $proyecto, 'facturas')->get()
            ->map(fn (Factura $f) => [
                'id' => $f->id,
                'numero' => $f->numero,
                'fecha' => $f->fecha->toDateString(),
                'proveedor' => self::proveedor($f->proveedor),
                'volumen_m3' => (float) $f->volumen_m3,
                'total' => (float) $f->total,
                'tiene_archivo' => (bool) $f->archivo_ruta,
            ])]);
    }

    /**
     * Los topes de cantidad y de peso y el cupo se revisan antes del primer byte: una vez que el
     * ZIP empieza a salir ya no se puede responder con un error.
     */
    public function zip(ExportarDocumentosRequest $request, Proyecto $proyecto, string $tipo, ArmadorZip $armador): StreamedResponse
    {
        $consulta = $this->consulta($request, $proyecto, $tipo);
        $total = $consulta->count();
        if ($total === 0) {
            self::rechazar('sin_documentos', 'No hay documentos que coincidan con la búsqueda.');
        }
        if ($total > self::TOPE) {
            self::rechazar('demasiados_documentos', 'Se pueden exportar hasta '.self::TOPE." documentos a la vez y hay {$total}. Acote los filtros o la selección.");
        }

        $medicion = $armador->medir($consulta->lazy()->map(fn (GuiaRemision|Factura $d) => [
            'ruc' => $d->proveedor->ruc,
            'numero' => $d->numero,
            'ruta' => $d instanceof GuiaRemision ? $d->foto_ruta : $d->archivo_ruta,
        ]));
        $maximo = config('services.exportaciones.max_mb');
        if ($medicion['bytes'] > $maximo * 1024 * 1024) {
            $mb = (int) ceil($medicion['bytes'] / 1024 / 1024);
            self::rechazar('demasiado_peso', "El ZIP pesaría {$mb} MB y el máximo es {$maximo} MB. Acote los filtros o la selección, por ejemplo con un rango de fechas menor.");
        }

        $encabezado = $this->encabezado($request, $proyecto, $tipo, $consulta);
        $candado = self::tomarCupo();
        $segundos = config('services.exportaciones.candado_segundos');

        return response()->stream(function () use ($armador, $medicion, $encabezado, $candado, $segundos) {
            try {
                // Lo que sigue saliendo renueva el plazo, aunque sea a mitad de un archivo: vence solo si el envío deja de avanzar.
                $armador->transmitir($medicion['documentos'], $encabezado, fn () => $candado->refresh($segundos));
            } finally {
                $candado->release();
            }
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$this->nombreZip($request, $proyecto, $tipo).'"',
            // Sin esto nginx guarda la respuesta en su propio disco antes de mandarla.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Una de las ranuras de exportación de toda la app. Vence sola si el proceso muere sin
     * liberarla, y se libera también al terminar la petición si el cliente corta la descarga.
     */
    private static function tomarCupo(): Lock
    {
        $segundos = config('services.exportaciones.candado_segundos');
        for ($i = 0; $i < config('services.exportaciones.simultaneas'); $i++) {
            $candado = Cache::lock("exportar-zip:{$i}", $segundos);
            if ($candado->get()) {
                register_shutdown_function(fn () => $candado->release());

                return $candado;
            }
        }

        throw new HttpResponseException(response()->json(
            ['message' => 'Hay otras exportaciones en curso. Intente de nuevo en un minuto.', 'code' => 'sin_cupo'],
            Response::HTTP_TOO_MANY_REQUESTS,
        ));
    }

    /** Con `ids`, solo la selección dentro del proyecto; sin ellos, los filtros. */
    private function consulta(ExportarDocumentosRequest $request, Proyecto $proyecto, string $tipo): HasMany
    {
        $relacion = $tipo === 'guias' ? $proyecto->guias() : $proyecto->facturas();
        $ids = $request->validated('ids');

        return $relacion
            ->when($ids, fn ($q) => $q->whereKey($ids), fn ($q) => $q
                ->when($request->validated('desde'), fn ($q, $desde) => $q->whereDate('fecha', '>=', $desde))
                ->when($request->validated('hasta'), fn ($q, $hasta) => $q->whereDate('fecha', '<=', $hasta))
                ->when($request->validated('proveedor_id'), fn ($q, $id) => $q->where('proveedor_id', $id))
                ->when($tipo === 'guias' ? $request->validated('estado') : null, fn ($q, $estado) => $q->where('estado_asignacion', $estado)))
            ->with('proveedor')
            ->orderByDesc('fecha')
            ->orderByDesc('id');
    }

    /** @return list<string> */
    private function encabezado(ExportarDocumentosRequest $request, Proyecto $proyecto, string $tipo, HasMany $consulta): array
    {
        if ($ids = $request->validated('ids')) {
            // Solo los del proyecto, en el orden pedido: los ajenos o repetidos no se cuentan.
            $ids = array_values(array_unique(array_intersect($ids, $consulta->pluck('id')->all())));
            $pedido = 'selección de '.count($ids).(count($ids) === 1 ? ' documento' : ' documentos').' (id '.implode(', ', $ids).')';
        } else {
            $proveedor = Proveedor::find($request->validated('proveedor_id'));
            $pedido = implode(', ', array_filter([
                ($d = $request->validated('desde')) ? "desde {$d}" : null,
                ($h = $request->validated('hasta')) ? "hasta {$h}" : null,
                $proveedor ? "proveedor {$proveedor->ruc} {$proveedor->razon_social}" : null,
                $tipo === 'guias' && ($e = $request->validated('estado')) ? "estado {$e}" : null,
            ])) ?: 'todos los documentos del proyecto';
        }

        return [
            'Exportación de '.($tipo === 'guias' ? 'guías de remisión' : 'facturas'),
            "Proyecto: {$proyecto->codigo} {$proyecto->nombre}",
            'Generado: '.now()->format('Y-m-d H:i'),
            "Pedido: {$pedido}",
        ];
    }

    /** `<tipo>-<proyecto>-<desde>_<hasta>.zip`; «seleccion» con ids, «todo» sin fechas. */
    private function nombreZip(ExportarDocumentosRequest $request, Proyecto $proyecto, string $tipo): string
    {
        $desde = $request->validated('desde');
        $hasta = $request->validated('hasta');
        $rango = match (true) {
            (bool) $request->validated('ids') => 'seleccion',
            ! $desde && ! $hasta => 'todo',
            default => ($desde ?? 'inicio').'_'.($hasta ?? 'fin'),
        };

        return ArmadorZip::sanear("{$tipo}-{$proyecto->codigo}-{$rango}").'.zip';
    }

    private static function proveedor(Proveedor $p): array
    {
        return ['id' => $p->id, 'ruc' => $p->ruc, 'razon_social' => $p->razon_social];
    }

    private static function rechazar(string $codigo, string $mensaje): never
    {
        throw new HttpResponseException(response()->json(
            ['message' => $mensaje, 'code' => $codigo],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ));
    }
}
