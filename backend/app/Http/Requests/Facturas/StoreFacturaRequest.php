<?php

namespace App\Http\Requests\Facturas;

use App\Http\Controllers\Api\V1\FacturaController;
use App\Support\NumeroComprobante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CU11 pasos 1–5 con CU12: datos de la factura, su archivo, el detalle por resistencia, los otros
 * cargos y las guías que cobra. Llega como multipart por el archivo, que viene como tal (registro
 * manual) o como la referencia que devolvió la lectura con OCR, para no subirlo dos veces. El
 * número repetido, las guías disponibles y la coincidencia de volúmenes se validan en el
 * controlador, dentro de la transacción.
 */
class StoreFacturaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->numero)) {
            // F001-5228 y F001-00005228 son la misma factura.
            $this->merge(['numero' => NumeroComprobante::normalizar($this->numero)]);
        }
    }

    public function rules(): array
    {
        return [
            'proyecto_id' => ['required', 'integer', 'exists:proyectos,id'],
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,id'],
            'numero' => ['required', 'string', 'max:20'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'archivo' => ['required_without:archivo_temporal', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'archivo_temporal' => ['required_without:archivo', 'nullable', 'string', 'regex:'.FacturaController::PATRON_ARCHIVO_TEMPORAL],
            // Detalle por resistencia: lo que la concretera cobra de cada f'c.
            'lineas' => ['required', 'array', 'min:1', 'max:10'],
            'lineas.*.resistencia_id' => ['required', 'integer', 'distinct', Rule::exists('resistencias', 'id')],
            'lineas.*.volumen_m3' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999.99'],
            'lineas.*.precio_unitario' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999.99'],
            // Lo que no es concreto (bombeo, aditivos…): suma al subtotal, no al volumen.
            'cargos' => ['sometimes', 'array', 'max:20'],
            'cargos.*.descripcion' => ['required', 'string', 'max:200'],
            'cargos.*.importe' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999.99'],
            'igv' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            // CU12: toda factura se registra vinculada a sus guías.
            'guia_ids' => ['required', 'array', 'min:1', 'max:300'],
            'guia_ids.*' => ['required', 'integer', 'distinct'],
            // CU11 flujo alternativo 1: registrar aunque el volumen no coincida.
            'confirmado' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'required_without' => 'Este dato es requerido',
            'numeric' => 'Este dato es requerido',
            'integer' => 'Este dato es requerido',
            'max' => 'Debe tener como máximo :max caracteres',
            'fecha.date_format' => 'Ingrese una fecha válida',
            'fecha.before_or_equal' => 'La fecha no puede ser posterior a hoy',
            'archivo.file' => __('sicco.facturas.archivo_invalido'),
            'archivo.mimes' => __('sicco.facturas.archivo_invalido'),
            'archivo.max' => __('sicco.facturas.archivo_invalido'),
            'archivo_temporal.regex' => __('sicco.facturas.archivo_vencido'),
            'cargos.max' => 'Debe tener como máximo :max cargos',
            'cargos.*.descripcion.max' => 'Debe tener como máximo :max caracteres',
            'cargos.*.importe.decimal' => 'Ingrese como máximo dos decimales',
            'cargos.*.importe.gt' => 'Ingrese un valor mayor a cero',
            'cargos.*.importe.max' => 'El valor es demasiado grande',
            'lineas.required' => 'Agregue al menos una resistencia',
            'lineas.min' => 'Agregue al menos una resistencia',
            'lineas.max' => 'Debe tener como máximo :max resistencias',
            'lineas.*.resistencia_id.distinct' => __('sicco.facturas.resistencia_repetida'),
            'lineas.*.resistencia_id.exists' => 'La resistencia no existe',
            'lineas.*.volumen_m3.decimal' => 'Ingrese como máximo dos decimales',
            'lineas.*.precio_unitario.decimal' => 'Ingrese como máximo dos decimales',
            'lineas.*.volumen_m3.gt' => 'Ingrese un valor mayor a cero',
            'lineas.*.precio_unitario.gt' => 'Ingrese un valor mayor a cero',
            'lineas.*.volumen_m3.max' => 'El valor es demasiado grande',
            'lineas.*.precio_unitario.max' => 'El valor es demasiado grande',
            'igv.decimal' => 'Ingrese como máximo dos decimales',
            'igv.min' => 'El IGV no puede ser negativo',
            'igv.max' => 'El valor es demasiado grande',
            'guia_ids.required' => __('sicco.facturas.sin_guias'),
            'guia_ids.min' => __('sicco.facturas.sin_guias'),
            'guia_ids.max' => 'Debe tener como máximo :max guías',
            'guia_ids.*.distinct' => 'La guía está repetida',
        ];
    }
}
