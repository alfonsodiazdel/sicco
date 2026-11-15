<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProveedorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $textos = ['contacto_nombre', 'contacto_telefono', 'contacto_correo', 'correo_facturacion', 'direccion_fiscal', 'planta_despacho'];
        $this->merge(collect($textos)
            ->filter(fn ($campo) => is_string($this->input($campo)))
            ->mapWithKeys(fn ($campo) => [$campo => trim($this->input($campo)) ?: null])
            ->all());
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
            'contacto_nombre' => ['nullable', 'string', 'max:150'],
            'contacto_telefono' => ['nullable', 'string', 'regex:/^9\d{8}$/'],
            'contacto_correo' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'correo_facturacion' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'condicion_pago' => ['nullable', Rule::in(['contado', '30', '60', '90'])],
            'direccion_fiscal' => ['nullable', 'string', 'max:250'],
            'planta_despacho' => ['nullable', 'string', 'max:150'],
            'activo' => ['sometimes', 'boolean'],
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
            'contacto_nombre.max' => __('sicco.proveedores.contacto_nombre_max'),
            'contacto_telefono.regex' => __('sicco.proveedores.telefono_invalido'),
            'contacto_correo.email' => __('sicco.proveedores.correo_invalido'),
            'correo_facturacion.email' => __('sicco.proveedores.correo_invalido'),
            'condicion_pago.in' => __('sicco.proveedores.condicion_pago_invalida'),
            'direccion_fiscal.max' => __('sicco.proveedores.direccion_fiscal_max'),
            'planta_despacho.max' => __('sicco.proveedores.planta_despacho_max'),
        ];
    }
}
