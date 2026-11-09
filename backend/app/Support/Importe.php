<?php

namespace App\Support;

/**
 * Importes en soles con dos decimales (CU11 requerimiento especial). Se calculan en céntimos
 * enteros: en coma flotante 8.5 × 291.35 no da exacto y el redondeo podría caer del otro lado.
 */
final class Importe
{
    /** Volumen × precio unitario, redondeado al céntimo (medio céntimo hacia arriba). */
    public static function centimos(string|int|float $volumenM3, string|int|float $precioUnitario): int
    {
        // Centésimos de m³ × céntimos: escala 1e-4 sobre el sol, se lleva al céntimo.
        $producto = Volumen::centesimos($volumenM3) * Volumen::centesimos($precioUnitario);

        return intdiv($producto + 50, 100);
    }
}
