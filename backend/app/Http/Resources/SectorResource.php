<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SectorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nivel_id' => $this->nivel_id,
            'presupuesto_id' => $this->presupuesto_id,
            'nombre' => $this->nombre,
            // El cast decimal:2 da texto; el frontend opera con números.
            'volumen_presup_m3' => (float) $this->volumen_presup_m3,
            'volumen_vaciado_m3' => (float) $this->volumen_vaciado_m3,
            // CU05: null si está abierto.
            'fecha_culminacion' => $this->fecha_culminacion?->toDateString(),
        ];
    }
}
