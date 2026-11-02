<?php

namespace App\Http\Requests;

use App\Models\GrupoPartida;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registro (POST) y actualización (PUT) de un grupo del catálogo de partidas. */
class GrupoPartidaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre]);
    }

    public function rules(): array
    {
        $grupo = $this->route('grupo');

        return [
            // La colación de MySQL compara sin distinguir mayúsculas ni tildes.
            'nombre' => ['required', 'string', 'max:60', Rule::unique('grupos_partida', 'nombre')->ignore(
                $grupo instanceof GrupoPartida ? $grupo : null,
            )],
            'partida_control' => ['required', 'string', 'max:20', 'regex:/^\d+(\.\d+)*$/'],
            'orden' => ['required', 'integer', 'min:1', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'max' => 'Debe tener como máximo :max caracteres',
            'nombre.unique' => __('sicco.grupos.duplicado'),
            'partida_control.regex' => 'Use solo números separados por puntos',
            'orden.integer' => 'Ingrese un número entero',
            'orden.min' => 'Ingrese un valor mayor a cero',
            'orden.max' => 'Ingrese un valor menor o igual a :max',
        ];
    }
}
