<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NivelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'proyecto_id' => $this->proyecto_id,
            'nombre' => $this->nombre,
            'orden' => $this->orden,
        ];
    }
}
