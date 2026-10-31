<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProveedorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'ruc' => is_string($this->ruc) ? trim($this->ruc) : $this->ruc,
            'razon_social' => is_string($this->razon_social) ? trim($this->razon_social) : $this->razon_social,
        ]);
    }

    public function rules(): array
    {
        return [
            // RUC peruano: 11 dígitos; 10 persona natural, 15/16/17 otros, 20 persona jurídica.
            'ruc' => [
                'required',
                'string',
                'regex:/^(10|15|16|17|20)\d{9}$/',
                Rule::unique('proveedores', 'ruc')->ignore($this->route('proveedor')),
            ],
            'razon_social' => ['required', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return [
            'ruc' => 'RUC',
            'razon_social' => 'razón social',
        ];
    }

    public function messages(): array
    {
        return [
            'ruc.regex' => __('sicco.proveedores.ruc_invalido'),
            'ruc.unique' => __('sicco.proveedores.ruc_duplicado'),
        ];
    }
}
