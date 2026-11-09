<?php

namespace App\Http\Requests\Facturas;

use Illuminate\Foundation\Http\FormRequest;

/** CU12 paso 1: las guías por facturar son las del proveedor de la factura. */
class GuiasPorFacturarRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'integer' => 'Este dato es requerido',
        ];
    }
}
