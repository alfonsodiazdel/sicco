<?php

namespace App\Http\Requests\Facturas;

use Illuminate\Foundation\Http\FormRequest;

/** Guías que se suman a una factura ya registrada. */
class VincularGuiasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'guia_ids' => ['required', 'array', 'min:1', 'max:300'],
            'guia_ids.*' => ['required', 'integer', 'distinct'],
            'confirmado' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'guia_ids.required' => __('sicco.facturas.sin_guias'),
            'guia_ids.min' => __('sicco.facturas.sin_guias'),
            'guia_ids.max' => 'Debe tener como máximo :max guías',
            'guia_ids.*.distinct' => 'La guía está repetida',
        ];
    }
}
