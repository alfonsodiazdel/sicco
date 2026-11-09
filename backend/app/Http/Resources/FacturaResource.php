<?php

namespace App\Http\Resources;

use App\Models\Factura;
use App\Models\FacturaCargo;
use App\Models\FacturaLinea;
use App\Models\GuiaRemision;
use App\Support\Importe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Factura */
class FacturaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'proyecto_id' => $this->proyecto_id,
            'proveedor' => ProveedorResource::make($this->whenLoaded('proveedor')),
            'numero' => $this->numero,
            'fecha' => $this->fecha->toDateString(),
            // Los casts decimal:2 dan texto; el frontend opera con números.
            'volumen_m3' => (float) $this->volumen_m3,
            'subtotal' => (float) $this->subtotal,
            'igv' => (float) $this->igv,
            'total' => (float) $this->total,
            'diferencia_confirmada' => $this->diferencia_confirmada,
            'origen' => $this->origen,
            'cargos' => $this->whenLoaded('cargos', fn () => $this->cargos->map(fn (FacturaCargo $c) => [
                'id' => $c->id,
                'descripcion' => $c->descripcion,
                'importe' => (float) $c->importe,
            ])),
            'guias_count' => $this->whenCounted('guias'),
            'lineas' => $this->whenLoaded('lineas', fn () => $this->lineas->map(fn (FacturaLinea $l) => [
                'id' => $l->id,
                'resistencia' => ResistenciaResource::make($l->resistencia),
                'volumen_m3' => (float) $l->volumen_m3,
                'precio_unitario' => (float) $l->precio_unitario,
                'importe' => (float) $l->importe,
            ])),
            'guias' => $this->whenLoaded('guias', fn () => $this->guias->map(fn (GuiaRemision $g) => [
                // resolve() quita las relaciones no cargadas, como al responder la guía sola.
                ...GuiaResource::make($g)->resolve($request),
                'importe' => self::importeDeGuia($g, $this->resource),
            ])),
        ];
    }

    /** CU12 paso 3: volumen de la guía × precio de la línea con su resistencia; sin línea, null. */
    public static function importeDeGuia(GuiaRemision $guia, Factura $factura): ?float
    {
        $linea = $factura->lineas->firstWhere('resistencia_id', $guia->resistencia_id);

        return $linea ? Importe::centimos($guia->volumen_m3, $linea->precio_unitario) / 100 : null;
    }
}
