<?php

namespace App\Http\Resources;

use App\Models\Resistencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Resistencia */
class ResistenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fc_kg_cm2' => $this->fc_kg_cm2,
            'nombre' => $this->nombre,
        ];
    }
}
