<?php

namespace App\Http\Requests\Reportes;

use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Models\Proyecto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** CU16: mes y año del corte, entre el mes de inicio del proyecto y el mes actual. */
class ReporteCorteMesRequest extends FormRequest
{
    use AutorizaProyecto;

    /** Antes de validar: la regla del mes delataría el inicio de un proyecto ajeno. */
    public function authorize(): bool
    {
        $this->autorizarProyecto($this, $this->route('proyecto'));

        return true;
    }

    public function rules(): array
    {
        return [
            'anio' => ['bail', 'required', 'integer', 'between:2000,2100'],
            'mes' => ['bail', 'required', 'integer', 'between:1,12'],
        ];
    }

    public function messages(): array
    {
        return [
            'anio.required' => 'Seleccione el año',
            'anio.integer' => 'Seleccione un año válido',
            'anio.between' => 'Seleccione un año válido',
            'mes.required' => 'Seleccione el mes',
            'mes.integer' => 'Seleccione un mes válido',
            'mes.between' => 'Seleccione un mes válido',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            /** @var Proyecto $proyecto */
            $proyecto = $this->route('proyecto');
            $elegido = $this->periodo();
            $actual = (int) today(ReporteEatRequest::ZONA_HORARIA)->format('Ym');

            if ($elegido > $actual) {
                $validator->errors()->add('mes', 'El mes no puede ser posterior al mes actual');
            } elseif ($elegido < (int) $proyecto->fecha_inicio->format('Ym')) {
                $validator->errors()->add('mes', 'El mes no puede ser anterior al inicio del proyecto');
            }
        }];
    }

    public function anio(): int
    {
        return (int) $this->validated('anio');
    }

    public function mes(): int
    {
        return (int) $this->validated('mes');
    }

    /** AAAAMM, para comparar meses. */
    private function periodo(): int
    {
        return (int) $this->input('anio') * 100 + (int) $this->input('mes');
    }
}
