<?php

namespace App\Services\Ocr;

use App\Support\Importe;
use App\Support\NumeroComprobante;
use App\Support\Ruc;
use App\Support\Volumen;
use Illuminate\Support\Carbon;

/**
 * Datos leídos de la factura de concreto: la cabecera, cada ítem del detalle y las guías que cita.
 * Como en LecturaGuia, la confianza del modelo se rebaja cuando un valor no pasa una verificación
 * propia (RUC, fecha, cantidad × valor unitario = importe, subtotal + IGV = total…).
 */
final readonly class LecturaFactura
{
    public const CAMPOS = ['numero', 'fecha', 'proveedor', 'ruc', 'subtotal', 'igv', 'total'];

    private const CONFIANZA_DUDOSA = 0.4;

    /** Diferencia tolerada entre importes calculados y leídos, por redondeos de la concretera. */
    private const TOLERANCIA_CENTIMOS = 5;

    /**
     * @param  array<string, array{valor: string|int|float|null, confianza: float}>  $campos
     * @param  list<array{descripcion: ?string, codigo_producto: ?string, resistencia: ?int, es_concreto: bool, cantidad: ?float, valor_unitario: ?float, importe: ?float, confianza: float}>  $items
     * @param  list<string>  $guias  Números de guía que cita la factura, normalizados.
     * @param  array<string, mixed>  $payload  Respuesta del servicio, para auditoría.
     */
    public function __construct(public array $campos, public array $items, public array $guias, public array $payload) {}

    /**
     * @throws LecturaNoDisponible si el archivo no es una factura.
     */
    public static function desdeDatos(array $datos, array $payload = []): self
    {
        if (($datos['es_factura'] ?? false) !== true) {
            throw new LecturaNoDisponible('El archivo no es una factura.');
        }

        $campos = [];
        foreach (self::CAMPOS as $campo) {
            [$valor, $tope] = self::verificar($campo, $datos[$campo]['valor'] ?? null);
            $campos[$campo] = [
                'valor' => $valor,
                'confianza' => $valor === null ? 0.0 : self::confianza($datos[$campo]['confianza'] ?? 0, $tope),
            ];
        }

        $items = [];
        foreach (is_array($datos['items'] ?? null) ? $datos['items'] : [] as $item) {
            if (is_array($item) && ($verificado = self::item($item))) {
                $items[] = $verificado;
            }
        }

        // Los ítems deben sumar el subtotal leído; si no, alguno está mal leído o falta.
        $subtotal = $campos['subtotal']['valor'];
        if ($subtotal !== null && $items) {
            $suma = array_sum(array_map(fn (array $i) => Volumen::centesimos($i['importe'] ?? 0), $items));
            if (abs($suma - Volumen::centesimos($subtotal)) > self::TOLERANCIA_CENTIMOS) {
                $items = array_map(fn (array $i) => [...$i, 'confianza' => min($i['confianza'], self::CONFIANZA_DUDOSA)], $items);
            }
        }

        // Subtotal + IGV = total.
        if (! in_array(null, [$subtotal, $campos['igv']['valor'], $campos['total']['valor']], true)) {
            $diferencia = Volumen::centesimos($subtotal) + Volumen::centesimos($campos['igv']['valor']) - Volumen::centesimos($campos['total']['valor']);
            if (abs($diferencia) > self::TOLERANCIA_CENTIMOS) {
                foreach (['subtotal', 'igv', 'total'] as $campo) {
                    $campos[$campo]['confianza'] = min($campos[$campo]['confianza'], self::CONFIANZA_DUDOSA);
                }
            }
        }

        $guias = [];
        foreach (is_array($datos['guias'] ?? null) ? $datos['guias'] : [] as $numero) {
            $normalizado = is_string($numero) ? NumeroComprobante::normalizar($numero) : null;
            if ($normalizado && NumeroComprobante::esValido($normalizado)) {
                $guias[$normalizado] = $normalizado;
            }
        }

        return new self($campos, $items, array_values($guias), $payload ?: $datos);
    }

    public function valor(string $campo): string|int|float|null
    {
        return $this->campos[$campo]['valor'] ?? null;
    }

    /** La menor confianza entre la cabecera y los ítems leídos; null si no se leyó nada. */
    public function confianzaMinima(): ?float
    {
        $confianzas = [
            ...array_column(array_filter($this->campos, fn (array $c) => $c['valor'] !== null), 'confianza'),
            ...array_column($this->items, 'confianza'),
        ];

        return $confianzas ? min($confianzas) : null;
    }

    private static function confianza(mixed $informada, float $tope): float
    {
        return round(min(max((float) $informada, 0), 1, $tope), 2);
    }

    /** @return array{0: string|float|null, 1: float} */
    private static function verificar(string $campo, mixed $valor): array
    {
        if (in_array($campo, ['subtotal', 'igv', 'total'], true)) {
            return is_numeric($valor) && (float) $valor >= 0 ? [round((float) $valor, 2), 1] : [null, 0];
        }

        $texto = is_scalar($valor) ? trim(preg_replace('/\s+/u', ' ', (string) $valor)) : '';
        if ($texto === '') {
            return [null, 0];
        }

        return match ($campo) {
            'numero' => [$numero = NumeroComprobante::normalizar($texto), NumeroComprobante::esValido($numero) ? 1 : self::CONFIANZA_DUDOSA],
            'fecha' => self::fecha($texto),
            'ruc' => [$ruc = preg_replace('/\D/', '', $texto), Ruc::esValido($ruc) ? 1 : self::CONFIANZA_DUDOSA],
            default => [mb_substr($texto, 0, 200), 1],
        };
    }

    /** Un ítem del detalle; null si no tiene ni cantidad ni importe (una línea de texto suelta). */
    private static function item(array $item): ?array
    {
        $numero = fn (string $clave) => is_numeric($item[$clave] ?? null) && (float) $item[$clave] > 0 ? round((float) $item[$clave], 2) : null;
        $texto = fn (string $clave, int $maximo) => is_string($item[$clave] ?? null) && trim($item[$clave]) !== ''
            ? mb_substr(trim(preg_replace('/\s+/u', ' ', $item[$clave])), 0, $maximo)
            : null;

        $cantidad = $numero('cantidad');
        $unitario = $numero('valor_unitario');
        $importe = $numero('importe');
        if ($cantidad === null && $importe === null) {
            return null;
        }

        $resistencia = is_numeric($item['resistencia'] ?? null) && $item['resistencia'] >= 100 && $item['resistencia'] <= 700
            ? (int) $item['resistencia']
            : null;

        $tope = 1.0;
        if ($cantidad !== null && $unitario !== null && $importe !== null
            && abs(Importe::centimos($cantidad, $unitario) - Volumen::centesimos($importe)) > self::TOLERANCIA_CENTIMOS) {
            $tope = self::CONFIANZA_DUDOSA;
        }
        if ($cantidad === null || $unitario === null) {
            $tope = self::CONFIANZA_DUDOSA;
        }

        return [
            'descripcion' => $texto('descripcion', 200),
            'codigo_producto' => ($codigo = $texto('codigo_producto', 30)) ? mb_strtoupper(str_replace(' ', '', $codigo)) : null,
            'resistencia' => $resistencia,
            'es_concreto' => ($item['es_concreto'] ?? false) === true,
            'cantidad' => $cantidad,
            'valor_unitario' => $unitario,
            'importe' => $importe ?? ($cantidad !== null && $unitario !== null ? Importe::centimos($cantidad, $unitario) / 100 : null),
            'confianza' => self::confianza($item['confianza'] ?? 0, $tope),
        ];
    }

    /** Acepta la fecha con hora («2026-04-16 12:00:00»): las facturas electrónicas la imprimen. */
    private static function fecha(string $texto): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [null, 0];
        }
        $fecha = Carbon::create((int) $m[1], (int) $m[2], (int) $m[3]);

        return [$fecha->toDateString(), $fecha->isBetween(today()->subYear(), today()) ? 1 : self::CONFIANZA_DUDOSA];
    }
}
