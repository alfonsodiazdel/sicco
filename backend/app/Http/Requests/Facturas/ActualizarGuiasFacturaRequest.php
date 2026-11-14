<?php

namespace App\Http\Requests\Facturas;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CU12 sobre una factura ya registrada: la lista completa de guías que cobra. Las que faltan se
 * desvinculan y vuelven a quedar por facturar; las nuevas se vinculan. Que cada guía esté
 * disponible y que el volumen coincida se valida en el controlador, dentro de la transacción.
 */
class ActualizarGuiasFacturaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'guia_ids' => ['required', 'array', 'min:1', 'max:300'],
            'guia_ids.*' => ['required', 'integer', 'distinct'],
            // Guardar aunque el volumen no coincida con el detalle de la factura.
            'confirmado' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'guia_ids.required' => __('sicco.facturas.sin_guias'),
            'guia_ids.min' => __('sicco.facturas.sin_guias'),
            'guia_ids.max' => 'Debe tener como máximo :max guías',
            'guia_ids.*.required' => 'Este dato es requerido',
            'guia_ids.*.integer' => 'Este dato es requerido',
            'guia_ids.*.distinct' => 'La guía está repetida',
        ];
    }
}
