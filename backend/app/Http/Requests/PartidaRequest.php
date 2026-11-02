<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registro (POST) y actualización (PUT) de una partida del presupuesto (CU04). */
class PartidaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // El ítem es opcional: vacío se guarda como null.
        $item = is_string($this->item) ? trim($this->item) : $this->item;
        $this->merge([
            'item' => $item === '' ? null : $item,
            'descripcion' => is_string($this->descripcion) ? trim($this->descripcion) : $this->descripcion,
        ]);
    }

    public function rules(): array
    {
        $importe = ['required', 'numeric', 'decimal:0,2'];

        return [
            'grupo_id' => ['required', 'integer', Rule::exists('grupos_partida', 'id')],
            'item' => ['nullable', 'string', 'max:20', 'regex:/^[\d.]*$/'],
            'descripcion' => ['required', 'string', 'max:200'],
            'elemento' => ['required', Rule::in(config('sicco.partidas.elementos'))],
            'resistencia_id' => ['required', 'integer', Rule::exists('resistencias', 'id')->where('activo', true)],
            'metrado_m3' => [...$importe, 'gt:0', 'max:9999999999.99'],
            'factor_desperdicio' => [...$importe, 'min:0', 'max:100'],
            'precio_unitario' => [...$importe, 'gt:0', 'max:9999999999.99'],
            // Confirmación del desperdicio mayor a 15 % (la pide el frontend antes de enviar).
            'confirmado' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'in' => 'Este dato es requerido',
            'numeric' => 'Este dato es requerido',
            'max' => 'Debe tener como máximo :max caracteres',
            'decimal' => 'Ingrese como máximo dos decimales',
            'gt' => 'Ingrese un valor mayor a cero',
            'integer' => 'Este dato es requerido',
            'grupo_id.exists' => 'El grupo no existe',
            'resistencia_id.exists' => 'La resistencia no existe',
            'item.regex' => 'Use solo números separados por puntos',
            'factor_desperdicio.min' => 'Ingrese un valor mayor o igual a cero',
            'factor_desperdicio.max' => 'Ingrese un valor menor o igual a 100',
            'metrado_m3.max' => 'El valor es demasiado grande',
            'precio_unitario.max' => 'El valor es demasiado grande',
        ];
    }

    /** Los datos a guardar, sin la confirmación. */
    public function datos(): array
    {
        return $this->safe()->except('confirmado');
    }
}
