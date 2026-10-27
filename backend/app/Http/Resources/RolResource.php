<?php

namespace App\Http\Resources;

use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Rol */
class RolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clave' => $this->clave,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
        ];
    }
}
