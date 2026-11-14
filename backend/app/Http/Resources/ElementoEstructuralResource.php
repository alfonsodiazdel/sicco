<?php

namespace App\Http\Resources;

use App\Models\ElementoEstructural;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ElementoEstructural */
class ElementoEstructuralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'activo' => $this->activo,
            'en_uso' => $this->enUso(),
        ];
    }
}
