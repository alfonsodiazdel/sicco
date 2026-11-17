<?php

namespace App\Http\Requests\Facturas;

use Illuminate\Foundation\Http\FormRequest;

/** Eliminar una factura registrada por error, con el motivo para el registro de eliminadas. */
class EliminarFacturaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.string' => 'Ingrese un motivo válido',
            'motivo.max' => 'Debe tener como máximo :max caracteres',
        ];
    }
}
