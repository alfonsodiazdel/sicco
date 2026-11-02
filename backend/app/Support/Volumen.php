<?php

namespace App\Support;

/**
 * Los volúmenes llegan con dos decimales (texto de MySQL o número del JSON). Se comparan en
 * centésimos enteros: en coma flotante 0.1 + 0.2 no es 0.3 y un sector justo al límite fallaría.
 */
final class Volumen
{
    public static function centesimos(string|int|float|null $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }
}
