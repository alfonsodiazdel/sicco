<?php

namespace App\Http\Requests;

use App\Models\ElementoEstructural;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registro (POST) y actualización (PUT) de un elemento estructural. */
class ElementoEstructuralRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre]);
    }

    public function rules(): array
    {
        $elemento = $this->route('elemento');

        return [
            // La colación de MySQL compara sin distinguir mayúsculas ni tildes.
            'nombre' => ['required', 'string', 'max:30', Rule::unique('elementos_estructurales', 'nombre')->ignore(
                $elemento instanceof ElementoEstructural ? $elemento : null,
            )],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'max' => 'Debe tener como máximo :max caracteres',
            'nombre.unique' => __('sicco.elementos.duplicado'),
            'boolean' => 'Este dato es requerido',
        ];
    }
}
