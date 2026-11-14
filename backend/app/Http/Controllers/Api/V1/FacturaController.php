<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\OcrServiceInterface;
use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facturas\ActualizarGuiasFacturaRequest;
use App\Http\Requests\Facturas\GuiasPorFacturarRequest;
use App\Http\Requests\Facturas\LeerFacturaRequest;
use App\Http\Requests\Facturas\StoreFacturaRequest;
use App\Http\Resources\FacturaResource;
use App\Http\Resources\GuiaResource;
use App\Models\Factura;
use App\Models\GuiaRemision;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Services\Guias\ResolutorResistencia;
use App\Services\Ocr\LecturaFactura;
use App\Services\Ocr\LecturaNoDisponible;
use App\Support\Importe;
use App\Support\NumeroComprobante;
use App\Support\Volumen;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CU11 Registrar factura de concreto, con CU12 Vincular factura con guías de remisión. */
class FacturaController extends Controller
{
    use AutorizaProyecto;

    /** Nombre del archivo que devuelve la lectura: un UUID y la extensión. */
    public const PATRON_ARCHIVO_TEMPORAL = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|pdf)$/';

    /** Lo que se guarda de la lectura entre la lectura y el registro. */
    private const HORAS_LECTURA = 24;

    /** Las facturas del proyecto, de la más reciente a la más antigua. Su subtotal es el costo real. */
    public function index(Request $request, Proyecto $proyecto): AnonymousResourceCollection
    {
        $this->autorizarProyecto($request, $proyecto);

        return FacturaResource::collection(
            $proyecto->facturas()->with('proveedor')->withCount('guias')
                ->orderByDesc('fecha')->orderByDesc('id')->get(),
        );
    }

    /** La factura con su detalle y las guías que cobra, cada una con su importe. */
    public function show(Request $request, Factura $factura): FacturaResource
    {
        $this->autorizarProyecto($request, $factura->proyecto);

        return FacturaResource::make($factura->load([
            'proveedor',
            'lineas.resistencia',
            'cargos',
            'guias' => fn ($q) => $q->with(['proveedor', 'resistencia', 'producto'])->orderBy('fecha')->orderBy('id'),
        ]));
    }

    /**
     * CU12 paso 1: las guías del proveedor y proyecto que aún no están facturadas, de la más
     * antigua a la más reciente. Lista vacía: «No hay guías pendientes del proveedor».
     */
    public function guiasPorFacturar(GuiasPorFacturarRequest $request, Proyecto $proyecto): AnonymousResourceCollection
    {
        $this->autorizarProyecto($request, $proyecto);

        return GuiaResource::collection(
            $proyecto->guias()
                ->where('proveedor_id', $request->validated('proveedor_id'))
                ->whereNull('factura_id')
                ->with(['proveedor', 'resistencia', 'producto'])
                ->orderBy('fecha')->orderBy('id')
                ->get(),
        );
    }

    /**
     * CU11 paso 1 con OCR: guarda el archivo y lo lee. Devuelve los datos para revisar: cabecera,
     * líneas de concreto con su resistencia resuelta, otros cargos y las guías que cita la factura,
     * ya buscadas entre las pendientes. Si el OCR falla, la respuesta trae el archivo sin lectura y
     * la pantalla pasa al ingreso manual con el archivo ya adjunto.
     */
    public function lectura(LeerFacturaRequest $request, OcrServiceInterface $ocr, ResolutorResistencia $resolutor): JsonResponse
    {
        $proyecto = Proyecto::findOrFail($request->validated('proyecto_id'));
        $this->autorizarProyecto($request, $proyecto);

        $archivo = $request->file('archivo');
        $temporal = Str::uuid()->toString().'.'.self::extension($archivo);
        Storage::putFileAs(self::carpetaTemporal($request->user()->id), $archivo, $temporal);

        try {
            $lectura = $ocr->leerFactura($archivo->get(), (string) $archivo->getMimeType(), self::indicaciones());
        } catch (LecturaNoDisponible) {
            return response()->json([
                'archivo_temporal' => $temporal,
                'lectura' => null,
                'message' => __('sicco.facturas.no_leida'),
            ]);
        }

        Cache::put(self::claveLectura($request->user()->id, $temporal), [
            'payload' => $lectura->payload,
            'confianza' => $lectura->confianzaMinima(),
        ], now()->addHours(self::HORAS_LECTURA));

        return response()->json([
            'archivo_temporal' => $temporal,
            'lectura' => $this->presentar($lectura, $proyecto, $resolutor),
        ]);
    }

    /**
     * CU11 pasos 5–6 y CU12 paso 5: registra la factura con su detalle y sus otros cargos y vincula
     * las guías, que quedan «Facturadas». Las guías se bloquean para que dos facturas no cobren la
     * misma.
     */
    public function store(StoreFacturaRequest $request): JsonResponse
    {
        $proyecto = Proyecto::findOrFail($request->validated('proyecto_id'));
        $this->autorizarProyecto($request, $proyecto);
        $proveedorId = (int) $request->validated('proveedor_id');
        $usuarioId = $request->user()->id;

        $temporal = $request->validated('archivo_temporal');
        $archivo = $request->file('archivo');
        if ($temporal && ! $archivo && ! Storage::exists(self::carpetaTemporal($usuarioId).'/'.$temporal)) {
            throw ValidationException::withMessages(['archivo' => __('sicco.facturas.archivo_vencido')]);
        }
        // Con un archivo nuevo, la lectura anterior no corresponde a este archivo.
        $lectura = $temporal && ! $archivo ? Cache::get(self::claveLectura($usuarioId, $temporal)) : null;

        $lineas = collect($request->validated('lineas'))->map(fn (array $l) => [
            'resistencia_id' => (int) $l['resistencia_id'],
            'volumen' => Volumen::centesimos($l['volumen_m3']),
            'precio' => Volumen::centesimos($l['precio_unitario']),
            'importe' => Importe::centimos($l['volumen_m3'], $l['precio_unitario']),
        ]);
        $cargos = collect($request->validated('cargos') ?? [])->map(fn (array $c) => [
            'descripcion' => trim($c['descripcion']),
            'importe' => Volumen::centesimos($c['importe']),
        ]);
        // El costo real incluye los otros cargos; el volumen facturado, solo el concreto.
        $subtotal = $lineas->sum('importe') + $cargos->sum('importe');
        $igv = Volumen::centesimos($request->validated('igv'));

        try {
            $factura = DB::transaction(function () use ($request, $proyecto, $proveedorId, $usuarioId, $lineas, $cargos, $subtotal, $igv, $archivo, $temporal, $lectura) {
                // CU11 flujo alternativo 2.
                if (Factura::where('proveedor_id', $proveedorId)->where('numero', $request->validated('numero'))->exists()) {
                    throw ValidationException::withMessages(['numero' => __('sicco.facturas.duplicada')]);
                }

                $guias = $this->guiasDisponibles($request->validated('guia_ids'), $proyecto->id, $proveedorId);

                // CU11 paso 5 y flujo alternativo 1: el volumen de cada resistencia debe coincidir.
                $diferencia = self::hayDiferencia($lineas, $guias);
                if ($diferencia && ! $request->boolean('confirmado')) {
                    throw new HttpResponseException(response()->json([
                        'message' => __('sicco.facturas.volumen_no_coincide'),
                        'code' => 'requiere_confirmacion',
                        'errors' => ['guia_ids' => [__('sicco.facturas.volumen_no_coincide')]],
                    ], Response::HTTP_UNPROCESSABLE_ENTITY));
                }

                $nombre = $archivo ? Str::uuid()->toString().'.'.self::extension($archivo) : $temporal;
                $carpeta = "facturas/{$proyecto->id}";

                $factura = Factura::create([
                    ...$request->safe()->only(['numero', 'fecha']),
                    'proyecto_id' => $proyecto->id,
                    'proveedor_id' => $proveedorId,
                    'volumen_m3' => $lineas->sum('volumen') / 100,
                    'subtotal' => $subtotal / 100,
                    'igv' => $igv / 100,
                    'total' => ($subtotal + $igv) / 100,
                    'diferencia_confirmada' => $diferencia,
                    'origen' => $lectura ? 'ocr' : 'manual',
                    'ocr_payload' => $lectura['payload'] ?? null,
                    'ocr_confianza' => $lectura['confianza'] ?? null,
                    'archivo_ruta' => "{$carpeta}/{$nombre}",
                    'registrado_por' => $usuarioId,
                ]);
                $factura->lineas()->createMany($lineas->map(fn (array $l) => [
                    'resistencia_id' => $l['resistencia_id'],
                    'volumen_m3' => $l['volumen'] / 100,
                    'precio_unitario' => $l['precio'] / 100,
                    'importe' => $l['importe'] / 100,
                ])->all());
                $factura->cargos()->createMany($cargos->map(fn (array $c) => [
                    'descripcion' => $c['descripcion'],
                    'importe' => $c['importe'] / 100,
                ])->all());

                // CU12 paso 5: se vinculan y quedan «Facturadas».
                GuiaRemision::whereKey($guias->modelKeys())->update(['factura_id' => $factura->id]);

                // Al final: si falla el almacenamiento, la factura no queda registrada sin su archivo.
                $guardado = $archivo
                    ? Storage::putFileAs($carpeta, $archivo, $nombre)
                    : Storage::move(self::carpetaTemporal($usuarioId).'/'.$temporal, "{$carpeta}/{$nombre}");
                if (! $guardado) {
                    throw new \RuntimeException('No se pudo guardar el archivo de la factura.');
                }

                return $factura;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos registros de la misma factura a la vez: el índice único detiene al segundo.
            throw ValidationException::withMessages(['numero' => __('sicco.facturas.duplicada')]);
        }

        if ($temporal) {
            Cache::forget(self::claveLectura($usuarioId, $temporal));
        }

        return response()->json([
            'data' => FacturaResource::make($factura->load('proveedor')->loadCount('guias')),
            'message' => __('sicco.facturas.registrada'),
        ], Response::HTTP_CREATED);
    }

    /**
     * CU12 después del registro: cambia las guías que cobra la factura, para las que llegaron
     * tarde, se olvidaron o se marcaron por error. Recibe la lista completa: las que salen vuelven a
     * quedar por facturar y las nuevas quedan «Facturadas». El volumen se compara de nuevo con el
     * detalle de la factura, como al registrarla.
     */
    public function actualizarGuias(ActualizarGuiasFacturaRequest $request, Factura $factura): FacturaResource
    {
        $this->autorizarProyecto($request, $factura->proyecto);
        $ids = $request->validated('guia_ids');

        DB::transaction(function () use ($request, $factura, $ids) {
            // Dos cambios a la vez sobre la misma factura: el segundo espera al primero.
            $factura = Factura::whereKey($factura->id)->lockForUpdate()->firstOrFail();

            $guias = $this->guiasDisponibles($ids, $factura->proyecto_id, $factura->proveedor_id, $factura->id);
            $lineas = $factura->lineas()->get()->map(fn ($l) => [
                'resistencia_id' => (int) $l->resistencia_id,
                'volumen' => Volumen::centesimos($l->volumen_m3),
            ]);

            $diferencia = self::hayDiferencia($lineas, $guias);
            if ($diferencia && ! $request->boolean('confirmado')) {
                throw new HttpResponseException(response()->json([
                    'message' => __('sicco.facturas.volumen_no_coincide'),
                    'code' => 'requiere_confirmacion',
                    'errors' => ['guia_ids' => [__('sicco.facturas.volumen_no_coincide')]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            GuiaRemision::where('factura_id', $factura->id)->whereKeyNot($guias->modelKeys())->update(['factura_id' => null]);
            GuiaRemision::whereKey($guias->modelKeys())->update(['factura_id' => $factura->id]);
            $factura->update(['diferencia_confirmada' => $diferencia]);
        });

        return FacturaResource::make($factura->refresh()->load([
            'proveedor',
            'lineas.resistencia',
            'cargos',
            'guias' => fn ($q) => $q->with(['proveedor', 'resistencia', 'producto'])->orderBy('fecha')->orderBy('id'),
        ])->loadCount('guias'))->additional(['message' => __('sicco.facturas.guias_actualizadas')]);
    }

    /**
     * Lo leído, listo para el formulario: cada dato de cabecera con su confianza y si debe
     * revisarse; el proveedor, si su RUC está registrado; las líneas de concreto con la resistencia
     * del catálogo (resuelta con los productos de la concretera, como en las guías); los otros
     * cargos; y las guías citadas que están pendientes, más un aviso por las que no.
     */
    private function presentar(LecturaFactura $lectura, Proyecto $proyecto, ResolutorResistencia $resolutor): array
    {
        $umbral = config('services.ocr.umbral_confianza');
        $proveedor = Proveedor::where('ruc', $lectura->valor('ruc'))->first();

        $lineas = [];
        $cargos = [];
        foreach ($lectura->items as $item) {
            if (! $item['es_concreto']) {
                $cargos[] = [
                    'descripcion' => $item['descripcion'],
                    'importe' => $item['importe'],
                    'revisar' => $item['importe'] === null || $item['descripcion'] === null || $item['confianza'] < $umbral,
                ];

                continue;
            }

            $resuelta = $resolutor->resolver($proveedor, $item['codigo_producto'], $item['resistencia'], $item['confianza']);
            $resistenciaId = $resuelta['resistencia']?->id;
            $confianza = min($item['confianza'], $resuelta['confianza']);

            // Una factura puede traer varias líneas del mismo concreto al mismo precio: se suman.
            $clave = $resistenciaId ? "{$resistenciaId}:{$item['valor_unitario']}" : 'sin:'.count($lineas);
            if (isset($lineas[$clave])) {
                $lineas[$clave]['volumen_m3'] = (Volumen::centesimos($lineas[$clave]['volumen_m3']) + Volumen::centesimos($item['cantidad'])) / 100;
                $lineas[$clave]['revisar'] = $lineas[$clave]['revisar'] || $confianza < $umbral;

                continue;
            }
            $lineas[$clave] = [
                'resistencia_id' => $resistenciaId,
                'volumen_m3' => $item['cantidad'],
                'precio_unitario' => $item['valor_unitario'],
                'descripcion' => $item['descripcion'],
                'codigo_producto' => $item['codigo_producto'],
                'revisar' => ! $resistenciaId || $item['cantidad'] === null || $item['valor_unitario'] === null || $confianza < $umbral,
            ];
        }

        return [
            'campos' => array_map(fn (array $c) => [
                ...$c,
                'revisar' => $c['valor'] === null || $c['confianza'] < $umbral,
            ], $lectura->campos),
            'proveedor_id' => $proveedor?->id,
            'lineas' => array_values($lineas),
            'cargos' => $cargos,
            'guias' => self::guiasCitadas($lectura->guias, $proyecto, $proveedor),
        ];
    }

    /**
     * CU12 con OCR: las guías que cita la factura y están pendientes se proponen marcadas; de las
     * demás se avisa por qué no (no registradas, de otro proyecto o ya facturadas).
     *
     * @param  list<string>  $citadas  Números normalizados.
     * @return array{sugeridas: list<int>, avisos: list<string>}
     */
    private static function guiasCitadas(array $citadas, Proyecto $proyecto, ?Proveedor $proveedor): array
    {
        if (! $citadas || ! $proveedor) {
            return ['sugeridas' => [], 'avisos' => []];
        }

        $delProveedor = GuiaRemision::where('proveedor_id', $proveedor->id)
            ->get(['id', 'numero', 'proyecto_id', 'factura_id'])
            ->keyBy(fn (GuiaRemision $g) => NumeroComprobante::normalizar($g->numero));

        $sugeridas = [];
        $avisos = [];
        foreach ($citadas as $numero) {
            $guia = $delProveedor->get($numero);
            $avisos[] = match (true) {
                ! $guia => __('sicco.facturas.citada_no_registrada', ['numero' => $numero]),
                (int) $guia->proyecto_id !== $proyecto->id => __('sicco.facturas.citada_otro_proyecto', ['numero' => $numero]),
                $guia->factura_id !== null => __('sicco.facturas.citada_facturada', ['numero' => $numero]),
                default => null,
            };
            if (end($avisos) === null) {
                array_pop($avisos);
                $sugeridas[] = $guia->id;
            }
        }

        return ['sugeridas' => $sugeridas, 'avisos' => $avisos];
    }

    /** Cómo indica cada concretera los datos, para el OCR (las mismas indicaciones de las guías). */
    private static function indicaciones(): array
    {
        return Proveedor::whereNotNull('indicaciones_ocr')->orderBy('razon_social')->get()
            ->map(fn (Proveedor $p) => "{$p->razon_social} (RUC {$p->ruc}): {$p->indicaciones_ocr}")
            ->all();
    }

    /** Archivos leídos y aún sin registrar, por usuario: solo quien lo subió puede usarlo. */
    private static function carpetaTemporal(int $usuarioId): string
    {
        return "facturas/temporal/{$usuarioId}";
    }

    private static function claveLectura(int $usuarioId, string $temporal): string
    {
        return "facturas:lectura:{$usuarioId}:{$temporal}";
    }

    /** La imagen o el PDF de la factura. Pasa por la API para revisar el acceso con la sesión. */
    public function archivo(Request $request, Factura $factura): StreamedResponse
    {
        $this->autorizarProyecto($request, $factura->proyecto);

        abort_unless(Storage::exists($factura->archivo_ruta), 404, __('sicco.facturas.archivo_no_disponible'));

        return Storage::response(
            $factura->archivo_ruta,
            "factura-{$factura->numero}.".pathinfo($factura->archivo_ruta, PATHINFO_EXTENSION),
            ['Cache-Control' => 'private, max-age=86400'],
        );
    }

    /**
     * Las guías elegidas, bloqueadas hasta terminar. Cada una debe ser del proyecto y proveedor de
     * la factura y no estar facturada (CU12 requerimiento especial: una sola factura por guía). Al
     * cambiar las guías de una factura, las que ya cobra esa misma factura siguen disponibles.
     *
     * @param  array<int, int|string>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, GuiaRemision>
     */
    private function guiasDisponibles(array $ids, int $proyectoId, int $proveedorId, ?int $facturaId = null): \Illuminate\Database\Eloquent\Collection
    {
        $guias = GuiaRemision::whereKey($ids)->orderBy('id')->lockForUpdate()->get();

        foreach ($ids as $i => $id) {
            $guia = $guias->find($id);
            $deOtraFactura = $guia?->factura_id && (int) $guia->factura_id !== $facturaId;
            if (! $guia || (int) $guia->proyecto_id !== $proyectoId || (int) $guia->proveedor_id !== $proveedorId || $deOtraFactura) {
                throw ValidationException::withMessages([
                    "guia_ids.{$i}" => __('sicco.facturas.guia_no_disponible', ['numero' => $guia->numero ?? "#{$id}"]),
                ]);
            }
        }

        return $guias;
    }

    /**
     * El volumen de cada resistencia en la factura frente al de las guías. Se compara por
     * resistencia y no solo el total: 16 m³ de f'c 280 no cobran 16 m³ de f'c 350.
     *
     * @param  Collection<int, array{resistencia_id: int, volumen: int}>  $lineas
     * @param  \Illuminate\Database\Eloquent\Collection<int, GuiaRemision>  $guias
     */
    private static function hayDiferencia(Collection $lineas, \Illuminate\Database\Eloquent\Collection $guias): bool
    {
        $facturado = $lineas->mapWithKeys(fn (array $l) => [$l['resistencia_id'] => $l['volumen']]);
        $recibido = $guias->groupBy('resistencia_id')
            ->map(fn ($g) => $g->sum(fn (GuiaRemision $guia) => Volumen::centesimos($guia->volumen_m3)));

        return $facturado->sortKeys()->all() !== $recibido->sortKeys()->all();
    }

    private static function extension(UploadedFile $archivo): string
    {
        return match ($archivo->getMimeType()) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
