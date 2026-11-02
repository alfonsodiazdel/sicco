<?php

namespace App\Http\Resources;

use App\Models\GuiaRemision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GuiaRemision */
class GuiaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'proyecto_id' => $this->proyecto_id,
            'proveedor' => ProveedorResource::make($this->whenLoaded('proveedor')),
            'numero' => $this->numero,
            'fecha' => $this->fecha->toDateString(),
            'resistencia' => ResistenciaResource::make($this->whenLoaded('resistencia')),
            'codigo_producto' => $this->whenLoaded('producto', fn () => $this->producto?->codigo),
            // El cast decimal:2 da texto; el frontend opera con números.
            'volumen_m3' => (float) $this->volumen_m3,
            'placa' => $this->placa,
            'estado_asignacion' => $this->estado_asignacion,
            'origen' => $this->origen,
        ];
    }
}
