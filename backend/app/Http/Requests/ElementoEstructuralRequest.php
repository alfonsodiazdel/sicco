<?php

namespace App\Http\Requests;

use App\Models\ElementoEstructural;
use App\Models\GrupoPartida;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Los grupos donde se puede elegir. Sin el campo (p. ej. al activar o desactivar), quedan como están.
            'grupos' => ['sometimes', 'array'],
            'grupos.*' => ['integer', 'distinct', Rule::exists('grupos_partida', 'id')],
        ];
    }

    /** No se quita un grupo cuyas partidas ya usan el elemento: quedarían fuera de lo permitido. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $elemento = $this->route('elemento');
            if ($validator->errors()->isNotEmpty() || ! $elemento instanceof ElementoEstructural || ! $this->has('grupos')) {
                return;
            }
            $quitados = $elemento->grupos()->whereNotIn('grupos_partida.id', $this->input('grupos'))->get();
            $enUso = $quitados->first(fn (GrupoPartida $g) => $g->usaElemento($elemento->nombre));
            if ($enUso) {
                $validator->errors()->add('grupos', __('sicco.elementos.grupo_en_uso', ['grupo' => $enUso->nombre]));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'max' => 'Debe tener como máximo :max caracteres',
            'nombre.unique' => __('sicco.elementos.duplicado'),
            'boolean' => 'Este dato es requerido',
            'grupos.array' => 'Este dato es requerido',
            'grupos.*.integer' => 'El grupo no existe',
            'grupos.*.distinct' => 'El grupo está repetido',
            'grupos.*.exists' => 'El grupo no existe',
        ];
    }
}
