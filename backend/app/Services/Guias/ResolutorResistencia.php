<?php

namespace App\Services\Guias;

use App\Models\ProductoProveedor;
use App\Models\Proveedor;
use App\Models\Resistencia;

/**
 * La resistencia de una guía leída, del dato más confiable al menos: el producto registrado de la
 * concretera, lo que indica su código según el patrón de la concretera y, por último, lo que leyó
 * el OCR. Solo vale una resistencia del catálogo.
 */
final class ResolutorResistencia
{
    /** Confianza de la resistencia deducida del código con el patrón de la concretera. */
    private const CONFIANZA_PATRON = 0.9;

    /** @return array{resistencia: ?Resistencia, producto: ?ProductoProveedor, confianza: float} */
    public function resolver(?Proveedor $proveedor, ?string $codigo, ?int $fcLeida, float $confianzaLeida): array
    {
        $producto = $proveedor && $codigo
            ? $proveedor->productos()->where('codigo', $codigo)->with('resistencia')->first()
            : null;
        if ($producto) {
            return ['resistencia' => $producto->resistencia, 'producto' => $producto, 'confianza' => 1.0];
        }

        $delCodigo = Resistencia::deFc($proveedor?->fcDelCodigo($codigo));
        if ($delCodigo) {
            return ['resistencia' => $delCodigo, 'producto' => null, 'confianza' => self::CONFIANZA_PATRON];
        }

        $leida = Resistencia::deFc($fcLeida);

        return ['resistencia' => $leida, 'producto' => null, 'confianza' => $leida ? $confianzaLeida : 0.0];
    }
}
