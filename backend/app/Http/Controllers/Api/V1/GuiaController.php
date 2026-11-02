<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\OcrServiceInterface;
use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guias\LeerGuiaRequest;
use App\Http\Requests\Guias\StoreGuiaRequest;
use App\Http\Resources\GuiaResource;
use App\Models\GuiaRemision;
use App\Models\ProductoProveedor;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Services\Guias\ResolutorResistencia;
use App\Services\Ocr\LecturaGuia;
use App\Services\Ocr\LecturaNoDisponible;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** CU06 Registrar guía de remisión, con CU07 Leer guía con OCR y CU08 Registrar guía manualmente. */
class GuiaController extends Controller
{
    use AutorizaProyecto;

    /** Nombre de la foto que devuelve la lectura: un UUID y la extensión. */
    public const PATRON_FOTO_TEMPORAL = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp)$/';

    /** Lo que se guarda de la lectura entre la lectura y el registro. */
    private const HORAS_LECTURA = 24;

    /**
     * CU06 pasos 2–4: guarda la foto y la lee con OCR. Si el OCR falla, la respuesta trae la foto
     * sin lectura y la pantalla pasa al registro manual (CU08) con la foto ya adjunta.
     */
    public function lectura(LeerGuiaRequest $request, OcrServiceInterface $ocr, ResolutorResistencia $resolutor): JsonResponse
    {
        $this->autorizarProyecto($request, Proyecto::findOrFail($request->validated('proyecto_id')));

        $foto = $request->file('foto');
        $temporal = Str::uuid()->toString().'.'.self::extension($foto);
        Storage::putFileAs(self::carpetaTemporal($request->user()->id), $foto, $temporal);

        try {
            $lectura = $ocr->leerGuia($foto->get(), $foto->getMimeType(), self::indicaciones());
        } catch (LecturaNoDisponible) {
            return response()->json([
                'foto_temporal' => $temporal,
                'lectura' => null,
                'message' => __('sicco.guias.no_leida'),
            ]);
        }

        Cache::put(self::claveLectura($request->user()->id, $temporal), [
            'payload' => $lectura->payload,
            'confianza' => $lectura->confianzaMinima(),
        ], now()->addHours(self::HORAS_LECTURA));

        return response()->json([
            'foto_temporal' => $temporal,
            'lectura' => $this->presentar($lectura, $resolutor),
        ]);
    }

    /** CU06 pasos 5–6 y CU08: registra la guía con estado «Sin sector». */
    public function store(StoreGuiaRequest $request): JsonResponse
    {
        $proyecto = Proyecto::findOrFail($request->validated('proyecto_id'));
        $this->autorizarProyecto($request, $proyecto);
        $usuarioId = $request->user()->id;

        $temporal = $request->validated('foto_temporal');
        $foto = $request->file('foto');
        if ($temporal && ! $foto && ! Storage::exists(self::carpetaTemporal($usuarioId).'/'.$temporal)) {
            throw ValidationException::withMessages(['foto' => __('sicco.guias.foto_vencida')]);
        }
        // Con foto nueva, la lectura anterior no corresponde a esta foto.
        $lectura = $temporal && ! $foto ? Cache::get(self::claveLectura($usuarioId, $temporal)) : null;

        try {
            $guia = DB::transaction(function () use ($request, $proyecto, $usuarioId, $temporal, $foto, $lectura) {
                $proveedor = $request->validated('proveedor_id')
                    ? Proveedor::findOrFail($request->validated('proveedor_id'))
                    // Proveedor que no estaba registrado, leído de la guía (arquitectura §6.9).
                    : Proveedor::firstOrCreate(
                        ['ruc' => $request->validated('proveedor_ruc')],
                        ['razon_social' => $request->validated('proveedor_razon_social')],
                    );

                if (GuiaRemision::where('proveedor_id', $proveedor->id)->where('numero', $request->validated('numero'))->exists()) {
                    throw ValidationException::withMessages(['numero' => __('sicco.guias.duplicada')]);
                }

                $producto = self::producto($proveedor, $request);

                $nombre = $foto ? Str::uuid()->toString().'.'.self::extension($foto) : $temporal;
                $carpeta = "guias/{$proyecto->id}";

                $guia = GuiaRemision::create([
                    ...$request->safe()->only(['numero', 'fecha', 'resistencia_id', 'volumen_m3', 'placa']),
                    'producto_id' => $producto?->id,
                    'proyecto_id' => $proyecto->id,
                    'proveedor_id' => $proveedor->id,
                    'foto_ruta' => "{$carpeta}/{$nombre}",
                    'estado_asignacion' => EstadoAsignacion::SinSector,
                    'origen' => $lectura ? OrigenGuia::Ocr : OrigenGuia::Manual,
                    'ocr_payload' => $lectura['payload'] ?? null,
                    'ocr_confianza' => $lectura['confianza'] ?? null,
                    'registrado_por' => $usuarioId,
                ]);

                // Al final: si falla el almacenamiento, la guía no queda registrada sin su foto.
                $guardada = $foto
                    ? Storage::putFileAs($carpeta, $foto, $nombre)
                    : Storage::move(self::carpetaTemporal($usuarioId).'/'.$temporal, "{$carpeta}/{$nombre}");
                if (! $guardada) {
                    throw new \RuntimeException('No se pudo guardar la foto de la guía.');
                }

                return $guia;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos registros de la misma guía a la vez: el índice único detiene al segundo.
            throw ValidationException::withMessages(['numero' => __('sicco.guias.duplicada')]);
        }

        if ($temporal) {
            Cache::forget(self::claveLectura($usuarioId, $temporal));
        }

        return GuiaResource::make($guia->load(['proveedor', 'resistencia', 'producto']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * CU07 paso 4: cada campo con su confianza y si debe revisarse; el proveedor, si ya está
     * registrado, y la resistencia del catálogo, resuelta con los productos de la concretera.
     */
    private function presentar(LecturaGuia $lectura, ResolutorResistencia $resolutor): array
    {
        $proveedor = Proveedor::where('ruc', $lectura->valor('ruc'))->first();
        $resuelta = $resolutor->resolver(
            $proveedor,
            $lectura->valor('codigo_producto'),
            $lectura->valor('resistencia'),
            $lectura->campos['resistencia']['confianza'],
        );

        $campos = $lectura->campos;
        $campos['resistencia'] = [
            'valor' => $resuelta['resistencia']?->fc_kg_cm2,
            'confianza' => $resuelta['confianza'],
        ];

        $umbral = config('services.ocr.umbral_confianza');

        return [
            'campos' => array_map(fn (array $c) => [
                ...$c,
                'revisar' => $c['valor'] === null || $c['confianza'] < $umbral,
            ], $campos),
            'proveedor_id' => $proveedor?->id,
            'resistencia_id' => $resuelta['resistencia']?->id,
        ];
    }

    /** Cómo indica cada concretera los datos en sus guías, para el OCR. */
    private static function indicaciones(): array
    {
        return Proveedor::whereNotNull('indicaciones_ocr')->orderBy('razon_social')->get()
            ->map(fn (Proveedor $p) => "{$p->razon_social} (RUC {$p->ruc}): {$p->indicaciones_ocr}")
            ->all();
    }

    /**
     * El producto de la guía según su código. Un código nuevo se registra con la resistencia que
     * confirmó el almacenero, para resolver solas las guías siguientes; uno registrado con otra
     * resistencia se rechaza, porque una de las dos está mal.
     */
    private static function producto(Proveedor $proveedor, StoreGuiaRequest $request): ?ProductoProveedor
    {
        $codigo = $request->validated('codigo_producto');
        if (! $codigo) {
            return null;
        }

        $producto = $proveedor->productos()->firstOrCreate(['codigo' => $codigo], [
            'descripcion' => $request->validated('descripcion_producto'),
            'resistencia_id' => $request->validated('resistencia_id'),
        ]);
        if ($producto->resistencia_id !== (int) $request->validated('resistencia_id')) {
            throw ValidationException::withMessages(['resistencia_id' => __('sicco.guias.resistencia_producto', [
                'codigo' => $codigo,
                'resistencia' => $producto->resistencia->nombre,
            ])]);
        }

        return $producto;
    }

    /** Fotos leídas y aún sin registrar, por usuario: solo quien la subió puede usarla. */
    private static function carpetaTemporal(int $usuarioId): string
    {
        return "guias/temporal/{$usuarioId}";
    }

    private static function claveLectura(int $usuarioId, string $temporal): string
    {
        return "guias:lectura:{$usuarioId}:{$temporal}";
    }

    private static function extension(UploadedFile $foto): string
    {
        return match ($foto->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
