<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartidaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'proyecto_id' => $this->proyecto_id,
            'grupo_id' => $this->grupo_id,
            'item' => $this->item,
            'descripcion' => $this->descripcion,
            'elemento' => $this->elemento,
            'resistencia_fc' => $this->resistencia_fc,
            // El cast decimal:2 da texto; el frontend opera con números.
            'metrado_m3' => (float) $this->metrado_m3,
            'factor_desperdicio' => (float) $this->factor_desperdicio,
            'precio_unitario' => (float) $this->precio_unitario,
        ];
    }
}
