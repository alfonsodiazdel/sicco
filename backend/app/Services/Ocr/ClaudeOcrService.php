<?php

namespace App\Services\Ocr;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use App\Contracts\OcrServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * CU07 con Claude: la foto va como imagen al Messages API y la respuesta llega como JSON validado
 * contra un esquema (salida estructurada), sin OCR clásico de por medio. Las guías cambian de
 * formato entre concreteras; el modelo ubica cada dato por su significado, no por su posición.
 */
class ClaudeOcrService implements OcrServiceInterface
{
    /** Lado mayor recomendado por Anthropic: más grande solo agrega latencia y costo. */
    private const LADO_MAXIMO = 1568;

    private const INSTRUCCIONES = <<<'TXT'
        Lees fotos de guías de remisión de concreto premezclado emitidas en Perú, tomadas con el celular
        en la obra. Extrae estos datos tal como aparecen impresos o escritos en la guía:

        - numero: serie y correlativo de la guía, p. ej. "T001-00012345" o "0001-0045678". No es el número de pedido, de ticket de balanza ni de orden de compra.
        - fecha: fecha de emisión o de entrega, en formato AAAA-MM-DD.
        - proveedor: razón social de la concretera que emite la guía (remitente).
        - ruc: RUC del remitente, 11 dígitos. No es el RUC del destinatario (la constructora).
        - codigo_producto: código del producto de concreto en el detalle de bienes transportados, p. ej. "70280NB5746" o "22259".
        - descripcion_producto: descripción del producto tal como aparece, p. ej. "HOLCIM CLASICO".
        - resistencia: resistencia f'c del concreto en kg/cm², solo el número entero, p. ej. 280. Suele estar en la descripción ("FC= 280", "f'c 210 kg/cm²"); algunas concreteras la indican en el código del producto (ver las indicaciones por concretera, si las hay).
        - volumen_m3: volumen despachado en metros cúbicos, como número.
        - placa: placa del camión mixer, p. ej. "ABC-123".

        Para cada dato devuelve "valor" y "confianza", un número de 0 a 1 que indica qué tan seguro estás
        de haberlo leído bien. Si un dato no aparece, está tapado o es ilegible, devuelve valor null y
        confianza 0: nunca lo inventes ni lo deduzcas. Baja la confianza si el texto está borroso, corregido
        a mano o admite dos lecturas.

        Marca es_guia_remision en false si la imagen no es una guía de remisión.
        TXT;

    public function __construct(
        private readonly Client $cliente,
        private readonly string $modelo,
    ) {}

    public function leerGuia(string $imagen, string $mediaType, array $indicaciones = []): LecturaGuia
    {
        [$imagen, $mediaType] = $this->reducir($imagen, $mediaType);
        $instrucciones = $indicaciones
            ? self::INSTRUCCIONES.'

Indicaciones por concretera:
- '.implode('
- ', $indicaciones)
            : self::INSTRUCCIONES;

        try {
            // Con esfuerzo bajo el modelo razona poco y la lectura cabe en los 20 s de CU07.
            // «fallbacks: default» reintenta en otro modelo si el pedido es rechazado por política.
            $mensaje = $this->cliente->beta->messages->create(
                model: $this->modelo,
                maxTokens: 4096,
                system: $instrucciones,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mediaType, 'data' => base64_encode($imagen)]],
                        ['type' => 'text', 'text' => 'Extrae los datos de esta guía de remisión.'],
                    ],
                ]],
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => self::esquema()],
                ],
                betas: ['server-side-fallback-2026-07-01'],
                fallbacks: 'default',
            );
        } catch (AnthropicException $e) {
            Log::warning('OCR de guía no disponible', ['error' => $e->getMessage()]);
            throw new LecturaNoDisponible('El servicio OCR no respondió.', previous: $e);
        }

        if ($mensaje->stopReason !== 'end_turn') {
            Log::warning('OCR de guía incompleto', ['stop_reason' => $mensaje->stopReason]);
            throw new LecturaNoDisponible("Lectura incompleta ({$mensaje->stopReason}).");
        }

        $texto = null;
        foreach ($mensaje->content as $bloque) {
            if ($bloque->type === 'text') {
                $texto = $bloque->text;
                break;
            }
        }
        $datos = json_decode((string) $texto, true);
        if (! is_array($datos)) {
            throw new LecturaNoDisponible('Respuesta del OCR no válida.');
        }

        return LecturaGuia::desdeDatos($datos, [
            'modelo' => $mensaje->model,
            'id' => $mensaje->id,
            'datos' => $datos,
        ]);
    }

    /** @return array<string, mixed> */
    private static function esquema(): array
    {
        $campo = fn (string|array $tipo) => [
            'type' => 'object',
            'properties' => ['valor' => ['type' => $tipo], 'confianza' => ['type' => 'number']],
            'required' => ['valor', 'confianza'],
            'additionalProperties' => false,
        ];

        $propiedades = ['es_guia_remision' => ['type' => 'boolean']];
        foreach (LecturaGuia::CAMPOS as $nombre) {
            $propiedades[$nombre] = $campo(match ($nombre) {
                'volumen_m3' => ['number', 'null'],
                'resistencia' => ['integer', 'null'],
                default => ['string', 'null'],
            });
        }

        return [
            'type' => 'object',
            'properties' => $propiedades,
            'required' => array_keys($propiedades),
            'additionalProperties' => false,
        ];
    }

    /**
     * Baja la foto a LADO_MAXIMO en JPEG. Si GD no puede abrirla (p. ej. HEIC) se envía tal cual.
     *
     * @return array{0: string, 1: string}
     */
    private function reducir(string $imagen, string $mediaType): array
    {
        $original = @imagecreatefromstring($imagen);
        if ($original === false) {
            return [$imagen, $mediaType];
        }

        $ancho = imagesx($original);
        $alto = imagesy($original);
        $escala = self::LADO_MAXIMO / max($ancho, $alto);
        if ($escala >= 1 && $mediaType === 'image/jpeg') {
            return [$imagen, $mediaType];
        }

        $reducida = $escala < 1
            ? imagescale($original, (int) round($ancho * $escala), (int) round($alto * $escala))
            : $original;

        ob_start();
        imagejpeg($reducida, null, 85);

        return [(string) ob_get_clean(), 'image/jpeg'];
    }
}
