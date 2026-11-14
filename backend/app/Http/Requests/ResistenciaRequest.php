<?php

namespace App\Http\Requests;

use App\Models\Resistencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registro (POST) y actualización (PUT) de una resistencia f'c. */
class ResistenciaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre]);
    }

    public function rules(): array
    {
        $resistencia = $this->route('resistencia');

        return [
            // `fc_kg_cm2` es unsignedSmallInteger y único; `nombre` es string(30).
            'fc_kg_cm2' => ['required', 'integer', 'min:1', 'max:65535', Rule::unique('resistencias', 'fc_kg_cm2')->ignore(
                $resistencia instanceof Resistencia ? $resistencia : null,
            )],
            'nombre' => ['required', 'string', 'max:30'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'integer' => 'Ingrese un número entero',
            'fc_kg_cm2.min' => 'Ingrese un valor mayor a cero',
            'fc_kg_cm2.max' => 'Ingrese un valor menor o igual a :max',
            'fc_kg_cm2.unique' => __('sicco.resistencias.duplicada'),
            'nombre.max' => 'Debe tener como máximo :max caracteres',
            'boolean' => 'Este dato es requerido',
        ];
    }
}
