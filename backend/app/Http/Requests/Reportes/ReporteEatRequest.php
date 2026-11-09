<?php

namespace App\Http\Requests\Reportes;

use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Models\Proyecto;
use Illuminate\Foundation\Http\FormRequest;

/** CU15: fecha de corte del Reporte EAT, entre el inicio del proyecto y hoy. */
class ReporteEatRequest extends FormRequest
{
    use AutorizaProyecto;

    // El hoy de la obra, no el del servidor (UTC): a las 19:00 en Lima ya es mañana en UTC.
    public const ZONA_HORARIA = 'America/Lima';

    /** Antes de validar: la regla de la fecha delataría el inicio de un proyecto ajeno. */
    public function authorize(): bool
    {
        $this->autorizarProyecto($this, $this->route('proyecto'));

        return true;
    }

    public function rules(): array
    {
        /** @var Proyecto $proyecto */
        $proyecto = $this->route('proyecto');

        return [
            'fecha_corte' => [
                'bail', 'required', 'date_format:Y-m-d',
                'before_or_equal:'.today(self::ZONA_HORARIA)->toDateString(),
                'after_or_equal:'.$proyecto->fecha_inicio->toDateString(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_corte.required' => 'Ingrese la fecha de corte',
            'fecha_corte.date_format' => 'Ingrese una fecha válida',
            'fecha_corte.before_or_equal' => 'La fecha de corte no puede ser posterior a hoy',
            'fecha_corte.after_or_equal' => 'La fecha de corte no puede ser anterior al inicio del proyecto',
        ];
    }
}
