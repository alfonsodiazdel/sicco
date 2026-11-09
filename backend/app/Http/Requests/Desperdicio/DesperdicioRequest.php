<?php

namespace App\Http\Requests\Desperdicio;

use App\Services\Desperdicio\CalculadoraDesperdicio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** CU13 pasos 1–2: nivel de detalle de la consulta (por defecto, sector). */
class DesperdicioRequest extends FormRequest
{
    public function rules(): array
    {
        return ['detalle' => ['nullable', Rule::in(CalculadoraDesperdicio::DETALLES)]];
    }

    public function messages(): array
    {
        return ['detalle.in' => 'Seleccione un nivel de detalle válido'];
    }
}
