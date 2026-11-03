<?php

namespace App\Http\Resources;

use App\Models\Asignacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Asignacion */
class AsignacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sector_id' => $this->sector_id,
            // El cast decimal:2 da texto; el frontend opera con números.
            'volumen_m3' => (float) $this->volumen_m3,
        ];
    }
}
