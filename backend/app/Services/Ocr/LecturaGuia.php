<?php

namespace App\Services\Ocr;

use App\Support\Ruc;
use Illuminate\Support\Carbon;

/**
 * Datos leídos de la guía (CU07 pasos 3 y 4): cada campo con su valor y una confianza de 0 a 1.
 * La confianza que informa el modelo se rebaja cuando el valor no pasa una verificación propia
 * (dígito verificador del RUC, fecha válida, formato del número…), porque la del modelo sola no
 * es confiable.
 */
final readonly class LecturaGuia
{
    public const CAMPOS = [
        'numero', 'fecha', 'proveedor', 'ruc', 'codigo_producto', 'descripcion_producto', 'resistencia',
        'volumen_m3', 'placa',
    ];

    /** Tope de confianza para un valor que no pasa la verificación. */
    private const CONFIANZA_DUDOSA = 0.4;

    /**
     * @param  array<string, array{valor: string|int|float|null, confianza: float}>  $campos
     * @param  array<string, mixed>  $payload  Respuesta del servicio, para auditoría.
     */
    public function __construct(public array $campos, public array $payload) {}

    /**
     * @param  array<string, mixed>  $datos  JSON con un objeto {valor, confianza} por campo.
     *
     * @throws LecturaNoDisponible si la imagen no es una guía de remisión.
     */
    public static function desdeDatos(array $datos, array $payload = []): self
    {
        if (($datos['es_guia_remision'] ?? false) !== true) {
            throw new LecturaNoDisponible('La imagen no es una guía de remisión.');
        }

        $campos = [];
        foreach (self::CAMPOS as $campo) {
            [$valor, $confianza] = self::verificar($campo, $datos[$campo]['valor'] ?? null);
            $informada = (float) ($datos[$campo]['confianza'] ?? 0);
            $campos[$campo] = [
                'valor' => $valor,
                'confianza' => $valor === null ? 0.0 : round(min(max($informada, 0), 1, $confianza), 2),
            ];
        }

        return new self($campos, $payload ?: $datos);
    }

    public function valor(string $campo): string|int|float|null
    {
        return $this->campos[$campo]['valor'] ?? null;
    }

    /** La menor confianza entre los campos leídos; null si no se leyó ninguno. */
    public function confianzaMinima(): ?float
    {
        $leidos = array_filter($this->campos, fn (array $c) => $c['valor'] !== null);

        return $leidos ? min(array_column($leidos, 'confianza')) : null;
    }

    /**
     * Normaliza el valor y devuelve el tope de confianza que admite. CU07 flujo alternativo 2:
     * lo que no se identifica queda vacío para registrarlo a mano.
     *
     * @return array{0: string|int|float|null, 1: float}
     */
    private static function verificar(string $campo, mixed $valor): array
    {
        if ($campo === 'resistencia') {
            // f'c en kg/cm²: el concreto premezclado va de unos 100 a 700.
            return is_numeric($valor) && $valor >= 100 && $valor <= 700 ? [(int) $valor, 1] : [null, 0];
        }

        if ($campo === 'volumen_m3') {
            if (! is_numeric($valor) || (float) $valor <= 0) {
                return [null, 0];
            }
            $volumen = round((float) $valor, 2);

            // Un mixer lleva hasta unos 12 m³; más es casi seguro un error de lectura.
            return [$volumen, $volumen <= 12 ? 1 : self::CONFIANZA_DUDOSA];
        }

        $texto = is_scalar($valor) ? trim(preg_replace('/\s+/u', ' ', (string) $valor)) : '';
        if ($texto === '') {
            return [null, 0];
        }

        return match ($campo) {
            'numero' => self::numero($texto),
            'fecha' => self::fecha($texto),
            'ruc' => [$ruc = preg_replace('/\D/', '', $texto), Ruc::esValido($ruc) ? 1 : self::CONFIANZA_DUDOSA],
            'placa' => [mb_strtoupper($texto), preg_match('/^[A-Z0-9]{3}-?[A-Z0-9]{3}$/i', $texto) ? 1 : self::CONFIANZA_DUDOSA],
            'codigo_producto' => [mb_substr(mb_strtoupper(str_replace(' ', '', $texto)), 0, 30), 1],
            default => [mb_substr($texto, 0, 200), 1],
        };
    }

    /** Serie de 4 caracteres y correlativo, p. ej. T001-00012345 (electrónica) o 0001-0012345. */
    private static function numero(string $texto): array
    {
        $numero = mb_strtoupper(str_replace(' ', '', $texto));

        return [mb_substr($numero, 0, 20), preg_match('/^[A-Z0-9]{4}-\d{1,8}$/', $numero) ? 1 : self::CONFIANZA_DUDOSA];
    }

    private static function fecha(string $texto): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [null, 0];
        }
        $fecha = Carbon::parse($texto);
        // Una guía de más de un año o del futuro casi seguro está mal leída.
        $razonable = $fecha->isBetween(today()->subYear(), today());

        return [$fecha->toDateString(), $razonable ? 1 : self::CONFIANZA_DUDOSA];
    }
}
