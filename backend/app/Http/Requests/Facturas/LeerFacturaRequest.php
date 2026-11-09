<?php

namespace App\Http\Requests\Facturas;

use Illuminate\Foundation\Http\FormRequest;

/** CU11 paso 1 con OCR: proyecto y archivo de la factura para leerla. */
class LeerFacturaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'proyecto_id' => ['required', 'integer', 'exists:proyectos,id'],
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'archivo.file' => __('sicco.facturas.archivo_invalido'),
            'archivo.mimes' => __('sicco.facturas.archivo_invalido'),
            'archivo.max' => __('sicco.facturas.archivo_invalido'),
        ];
    }
}
