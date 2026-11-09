<?php

namespace App\Services\Ocr;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use App\Contracts\OcrServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * CU07 y la lectura de facturas (CU11) con Claude: la foto o el PDF van al Messages API y la
 * respuesta llega como JSON validado contra un esquema (salida estructurada), sin OCR clásico de
 * por medio. Guías y facturas cambian de formato entre concreteras; el modelo ubica cada dato por
 * su significado, no por su posición.
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

    private const INSTRUCCIONES_FACTURA = <<<'TXT'
        Lees facturas electrónicas de concreto premezclado emitidas en Perú por una concretera a una
        constructora. Extrae estos datos tal como aparecen en la factura:

        - numero: serie y correlativo de la factura, p. ej. "F001-00002803" o "F012-180490". No es el número de pedido, de orden de compra ni de documento de referencia.
        - fecha: fecha de emisión, en formato AAAA-MM-DD.
        - proveedor: razón social de la concretera que emite la factura.
        - ruc: RUC del emisor, 11 dígitos. No es el RUC del cliente (la constructora).
        - subtotal: operaciones gravadas o valor de venta, sin IGV.
        - igv: monto del IGV.
        - total: importe total a pagar, con IGV (antes de detracciones o retenciones).
        - items: cada línea del detalle, con:
          - descripcion: tal como aparece, p. ej. "CONCRETO PREMEZCLADO FC280 KG/CM2" o "HOLCIM CLASICO".
          - codigo_producto: código del producto, si lo hay, p. ej. "70280NB5746". No es la unidad (MTQ, M3).
          - resistencia: resistencia f'c del concreto en kg/cm², solo el número entero, p. ej. 280; null si la línea no es concreto o no la indica.
          - es_concreto: true si la línea cobra concreto premezclado; false si es un servicio u otro cargo (bombeo, "concreto bombeable", aditivos, tiempo de espera, flete, etc.).
          - cantidad: cantidad facturada (m³ en el concreto).
          - valor_unitario: precio unitario SIN IGV. Si la factura muestra dos precios (p. ej. "V. Unit." y "P. Unit."), usa el que no incluye IGV.
          - importe: valor total de la línea sin IGV.
          - confianza: de 0 a 1, qué tan seguro estás de la línea completa.
        - guias: números de las guías de remisión que cita la factura (en el detalle, en observaciones o en "guías de remisión"), p. ej. "T006-00012289". Lista vacía si no cita ninguna.

        Para los datos de cabecera devuelve "valor" y "confianza" de 0 a 1. Si un dato no aparece o es
        ilegible, devuelve valor null y confianza 0: nunca lo inventes ni lo deduzcas.

        Marca es_factura en false si el archivo no es una factura.
        TXT;

    public function __construct(
        private readonly Client $cliente,
        private readonly string $modelo,
    ) {}

    public function leerGuia(string $imagen, string $mediaType, array $indicaciones = []): LecturaGuia
    {
        [$imagen, $mediaType] = $this->reducir($imagen, $mediaType);

        [$datos, $payload] = $this->consultar(
            self::conIndicaciones(self::INSTRUCCIONES, $indicaciones),
            ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mediaType, 'data' => base64_encode($imagen)]],
            'Extrae los datos de esta guía de remisión.',
            self::esquema(),
            'guía',
        );

        return LecturaGuia::desdeDatos($datos, $payload);
    }

    /**
     * El PDF va como documento: el modelo lee su texto y su imagen, más preciso que una foto. Una
     * imagen se reduce como la de la guía.
     */
    public function leerFactura(string $archivo, string $mediaType, array $indicaciones = []): LecturaFactura
    {
        if ($mediaType === 'application/pdf') {
            $bloque = ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($archivo)]];
        } else {
            [$archivo, $mediaType] = $this->reducir($archivo, $mediaType);
            $bloque = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mediaType, 'data' => base64_encode($archivo)]];
        }

        [$datos, $payload] = $this->consultar(
            self::conIndicaciones(self::INSTRUCCIONES_FACTURA, $indicaciones),
            $bloque,
            'Extrae los datos de esta factura.',
            self::esquemaFactura(),
            'factura',
        );

        return LecturaFactura::desdeDatos($datos, $payload);
    }

    /** @param  list<string>  $indicaciones */
    private static function conIndicaciones(string $instrucciones, array $indicaciones): string
    {
        return $indicaciones
            ? $instrucciones."\n\nIndicaciones por concretera:\n- ".implode("\n- ", $indicaciones)
            : $instrucciones;
    }

    /**
     * Envía el archivo con las instrucciones y devuelve el JSON de la salida estructurada.
     *
     * @param  array<string, mixed>  $bloque  Imagen o documento.
     * @param  array<string, mixed>  $esquema
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     *
     * @throws LecturaNoDisponible
     */
    private function consultar(string $instrucciones, array $bloque, string $pedido, array $esquema, string $documento): array
    {
        try {
            // Con esfuerzo bajo el modelo razona poco y la lectura cabe en los 20 s de CU07.
            // «fallbacks: default» reintenta en otro modelo si el pedido es rechazado por política.
            $mensaje = $this->cliente->beta->messages->create(
                model: $this->modelo,
                maxTokens: 4096,
                system: $instrucciones,
                messages: [[
                    'role' => 'user',
                    'content' => [$bloque, ['type' => 'text', 'text' => $pedido]],
                ]],
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => $esquema],
                ],
                betas: ['server-side-fallback-2026-07-01'],
                fallbacks: 'default',
            );
        } catch (AnthropicException $e) {
            Log::warning("OCR de {$documento} no disponible", ['error' => $e->getMessage()]);
            throw new LecturaNoDisponible('El servicio OCR no respondió.', previous: $e);
        }

        if ($mensaje->stopReason !== 'end_turn') {
            Log::warning("OCR de {$documento} incompleto", ['stop_reason' => $mensaje->stopReason]);
            throw new LecturaNoDisponible("Lectura incompleta ({$mensaje->stopReason}).");
        }

        $texto = null;
        foreach ($mensaje->content as $contenido) {
            if ($contenido->type === 'text') {
                $texto = $contenido->text;
                break;
            }
        }
        $datos = json_decode((string) $texto, true);
        if (! is_array($datos)) {
            throw new LecturaNoDisponible('Respuesta del OCR no válida.');
        }

        return [$datos, ['modelo' => $mensaje->model, 'id' => $mensaje->id, 'datos' => $datos]];
    }

    /** @return array<string, mixed> */
    private static function esquemaFactura(): array
    {
        $campo = fn (array $tipo) => [
            'type' => 'object',
            'properties' => ['valor' => ['type' => $tipo], 'confianza' => ['type' => 'number']],
            'required' => ['valor', 'confianza'],
            'additionalProperties' => false,
        ];
        $item = [
            'descripcion' => ['type' => ['string', 'null']],
            'codigo_producto' => ['type' => ['string', 'null']],
            'resistencia' => ['type' => ['integer', 'null']],
            'es_concreto' => ['type' => 'boolean'],
            'cantidad' => ['type' => ['number', 'null']],
            'valor_unitario' => ['type' => ['number', 'null']],
            'importe' => ['type' => ['number', 'null']],
            'confianza' => ['type' => 'number'],
        ];

        $propiedades = ['es_factura' => ['type' => 'boolean']];
        foreach (LecturaFactura::CAMPOS as $nombre) {
            $propiedades[$nombre] = $campo(in_array($nombre, ['subtotal', 'igv', 'total'], true) ? ['number', 'null'] : ['string', 'null']);
        }
        $propiedades['items'] = ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => $item,
            'required' => array_keys($item),
            'additionalProperties' => false,
        ]];
        $propiedades['guias'] = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => $propiedades,
            'required' => array_keys($propiedades),
            'additionalProperties' => false,
        ];
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
