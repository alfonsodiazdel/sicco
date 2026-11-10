<?php

namespace App\Http\Requests\Dashboard;

use App\Http\Requests\Reportes\ReporteEatRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** CU17 paso 3: el rango de meses del dashboard (AAAA-MM); por defecto, el mes en curso. */
class DashboardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'desde' => ['bail', 'nullable', 'date_format:Y-m'],
            'hasta' => ['bail', 'nullable', 'date_format:Y-m'],
        ];
    }

    public function messages(): array
    {
        return [
            'desde.date_format' => 'Seleccione un mes válido',
            'hasta.date_format' => 'Seleccione un mes válido',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->hasta() > $this->mesActual()) {
                $validator->errors()->add('hasta', 'El mes no puede ser posterior al mes actual');
            } elseif ($this->desde() > $this->hasta()) {
                $validator->errors()->add('desde', 'El mes inicial no puede ser posterior al mes final');
            }
        }];
    }

    /** AAAA-MM del primer mes del rango. */
    public function desde(): string
    {
        return $this->input('desde') ?: $this->hasta();
    }

    /** AAAA-MM del último mes del rango. */
    public function hasta(): string
    {
        return $this->input('hasta') ?: $this->mesActual();
    }

    /** Primer día del rango. */
    public function fechaInicio(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->desde())->toDateString();
    }

    /** Último día del rango: el cierre del mes final. */
    public function fechaCierre(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->hasta())->endOfMonth()->toDateString();
    }

    private function mesActual(): string
    {
        return today(ReporteEatRequest::ZONA_HORARIA)->format('Y-m');
    }
}
