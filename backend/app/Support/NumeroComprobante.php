<?php

namespace App\Support;

/**
 * Número de un comprobante electrónico de SUNAT: serie de 4 caracteres y correlativo de hasta 8
 * dígitos. Las concreteras lo imprimen con o sin ceros a la izquierda (F001-5228 y F001-00005228
 * son el mismo); se guarda con el correlativo a 8 dígitos para que la unicidad no dependa de eso.
 */
final class NumeroComprobante
{
    private const PATRON = '/^([A-Z0-9]{4})-0*(\d{1,8})$/';

    /** Mayúsculas, sin espacios y con el correlativo a 8 dígitos; lo que no es un número así, igual. */
    public static function normalizar(string $numero): string
    {
        $limpio = mb_strtoupper(preg_replace('/\s+/u', '', $numero));

        return preg_match(self::PATRON, $limpio, $m) ? $m[1].'-'.str_pad($m[2], 8, '0', STR_PAD_LEFT) : $limpio;
    }

    public static function esValido(string $numero): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{4}-\d{8}$/', $numero);
    }
}
