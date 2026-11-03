<?php

namespace App\Http\Requests\Guias;

use Illuminate\Foundation\Http\FormRequest;

/** CU10 pasos 1–2: filtro por rango de fechas. Sin fechas trae todas las guías pendientes. */
class GuiasSinSectorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_format' => 'Ingrese una fecha válida',
            'hasta.after_or_equal' => __('sicco.guias.rango_invalido'),
        ];
    }
}
