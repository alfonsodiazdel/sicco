<?php

namespace App\Http\Resources;

use App\Models\Resistencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Para el mantenedor: la resistencia con su estado y si está en uso. `ResistenciaResource` queda
 * como está para los selectores que ya lo consumen.
 *
 * @mixin Resistencia
 */
class ResistenciaMantenedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fc_kg_cm2' => $this->fc_kg_cm2,
            'nombre' => $this->nombre,
            'activo' => $this->activo,
            'en_uso' => $this->enUso(),
        ];
    }
}
