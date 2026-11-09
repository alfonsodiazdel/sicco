<?php

namespace App\Http\Requests\Alertas;

use App\Enums\EstadoAlerta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros de la pantalla «Alertas de consumo» (CU14): estado y proyecto, ambos opcionales. */
class ListarAlertasRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'estado' => ['nullable', Rule::enum(EstadoAlerta::class)],
            'proyecto_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return ['estado' => 'Seleccione un estado válido'];
    }
}
