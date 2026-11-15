<?php

namespace App\Http\Resources;

use App\Models\Factura;
use App\Models\FacturaCargo;
use App\Models\FacturaLinea;
use App\Models\GuiaRemision;
use App\Support\Importe;
use App\Support\Volumen;
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
            'volumen_guias_m3' => $this->when($this->guiasVinculadas() !== null, fn () => $this->guiasVinculadas() / 100),
            'volumen_pendiente_m3' => $this->when($this->guiasVinculadas() !== null, fn () => max(0, Volumen::centesimos($this->volumen_m3) - $this->guiasVinculadas()) / 100),
            'pendiente_por_resistencia' => $this->when($this->relationLoaded('lineas') && $this->relationLoaded('guias'), fn () => $this->pendientePorResistencia()),
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

    /** Volumen de las guías vinculadas en centésimos de m³; null si no se cargaron ni se sumaron. */
    private function guiasVinculadas(): ?int
    {
        if ($this->relationLoaded('guias')) {
            return $this->guias->sum(fn (GuiaRemision $g) => Volumen::centesimos($g->volumen_m3));
        }
        // withSum deja la columna en null cuando la factura no tiene guías.
        if (! array_key_exists('guias_sum_volumen_m3', $this->resource->getAttributes())) {
            return null;
        }

        return Volumen::centesimos($this->guias_sum_volumen_m3 ?? 0);
    }

    /** @return list<array{resistencia: ResistenciaResource, facturado_m3: float, en_guias_m3: float, faltan_m3: float}> */
    private function pendientePorResistencia(): array
    {
        $recibido = $this->guias->groupBy('resistencia_id')
            ->map(fn ($g) => $g->sum(fn (GuiaRemision $guia) => Volumen::centesimos($guia->volumen_m3)));

        return $this->lineas->map(function (FacturaLinea $l) use ($recibido) {
            $facturado = Volumen::centesimos($l->volumen_m3);
            $enGuias = (int) ($recibido[$l->resistencia_id] ?? 0);

            return [
                'resistencia' => ResistenciaResource::make($l->resistencia),
                'facturado_m3' => $facturado / 100,
                'en_guias_m3' => $enGuias / 100,
                'faltan_m3' => max(0, $facturado - $enGuias) / 100,
            ];
        })->filter(fn (array $r) => $r['faltan_m3'] > 0)->values()->all();
    }

    /** CU12 paso 3: volumen de la guía × precio de la línea con su resistencia; sin línea, null. */
    public static function importeDeGuia(GuiaRemision $guia, Factura $factura): ?float
    {
        $linea = $factura->lineas->firstWhere('resistencia_id', $guia->resistencia_id);

        return $linea ? Importe::centimos($guia->volumen_m3, $linea->precio_unitario) / 100 : null;
    }
}
