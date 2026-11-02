<?php

namespace App\Http\Requests\Guias;

use Illuminate\Foundation\Http\FormRequest;

/** CU06 pasos 1–3: proyecto y foto de la guía para enviarla al OCR (CU07). */
class LeerGuiaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'proyecto_id' => ['required', 'integer', 'exists:proyectos,id'],
            'foto' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'foto.file' => __('sicco.guias.foto_invalida'),
            'foto.mimes' => __('sicco.guias.foto_invalida'),
            'foto.max' => __('sicco.guias.foto_invalida'),
        ];
    }
}
