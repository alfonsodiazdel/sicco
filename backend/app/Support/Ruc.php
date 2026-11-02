<?php

namespace App\Support;

/** RUC peruano: 11 dígitos con prefijo de tipo de contribuyente y dígito verificador de SUNAT. */
final class Ruc
{
    private const FACTORES = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function esValido(?string $ruc): bool
    {
        if ($ruc === null || ! preg_match('/^(10|15|16|17|20)\d{9}$/', $ruc)) {
            return false;
        }

        $suma = 0;
        foreach (self::FACTORES as $i => $factor) {
            $suma += (int) $ruc[$i] * $factor;
        }
        // Módulo 11: 10 se escribe 0 y 11 se escribe 1.
        $digito = (11 - $suma % 11) % 10;

        return $digito === (int) $ruc[10];
    }
}
