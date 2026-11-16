<?php

namespace App\Http\Requests;

use App\Models\ElementoEstructural;
use App\Models\GrupoPartida;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Los elementos que admite el grupo. Sin el campo, se dejan como están.
            'elementos' => ['sometimes', 'array'],
            'elementos.*' => ['integer', 'distinct', Rule::exists('elementos_estructurales', 'id')],
        ];
    }

    /** No se quita un elemento que ya usan partidas del grupo: quedarían fuera de lo permitido. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $grupo = $this->route('grupo');
            if ($validator->errors()->isNotEmpty() || ! $grupo instanceof GrupoPartida || ! $this->has('elementos')) {
                return;
            }
            $quitados = $grupo->elementos()->whereNotIn('elementos_estructurales.id', $this->input('elementos'))->get();
            $enUso = $quitados->first(fn (ElementoEstructural $e) => $grupo->usaElemento($e->nombre));
            if ($enUso) {
                $validator->errors()->add('elementos', __('sicco.grupos.elemento_en_uso', ['elemento' => $enUso->nombre]));
            }
        }];
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
            'elementos.array' => 'Este dato es requerido',
            'elementos.*.integer' => 'El elemento no existe',
            'elementos.*.distinct' => 'El elemento está repetido',
            'elementos.*.exists' => 'El elemento no existe',
        ];
    }
}
