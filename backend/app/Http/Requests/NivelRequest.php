<?php

namespace App\Http\Requests;

use App\Models\Nivel;
use App\Models\Proyecto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registro (POST) y actualización (PUT) de un nivel del proyecto (CU05). */
class NivelRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre]);
    }

    public function rules(): array
    {
        $nivel = $this->route('nivel');
        $proyecto = $this->route('proyecto');
        $proyectoId = $nivel instanceof Nivel ? $nivel->proyecto_id : ($proyecto instanceof Proyecto ? $proyecto->id : null);

        return [
            'nombre' => ['required', 'string', 'max:40', Rule::unique('niveles', 'nombre')
                ->where('proyecto_id', $proyectoId)
                ->ignore($nivel instanceof Nivel ? $nivel : null)],
            'orden' => ['required', 'integer', 'min:1', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'max' => 'Debe tener como máximo :max caracteres',
            'nombre.unique' => __('sicco.niveles.duplicado'),
            'orden.integer' => 'Ingrese un número entero',
            'orden.min' => 'Ingrese un valor mayor a cero',
            'orden.max' => 'Ingrese un valor menor o igual a :max',
        ];
    }
}
