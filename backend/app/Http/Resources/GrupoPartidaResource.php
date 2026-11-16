<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrupoPartidaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'partida_control' => $this->partida_control,
            'orden' => $this->orden,
            // Ids de los elementos estructurales que admite el grupo.
            'elementos' => $this->elementos->pluck('id'),
        ];
    }
}
