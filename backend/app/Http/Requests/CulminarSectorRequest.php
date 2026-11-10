<?php

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Http\Requests\Reportes\ReporteEatRequest;
use App\Models\Sector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * CU05: fecha en que se culminó el sector. No es futura, no es anterior al inicio del proyecto ni a la
 * última guía vaciada en el sector: el reporte EAT (CU15) la compara con la fecha de corte.
 */
class CulminarSectorRequest extends FormRequest
{
    use AutorizaProyecto;

    /** Antes de validar: las reglas de la fecha delatarían datos de un proyecto ajeno. */
    public function authorize(): bool
    {
        $this->autorizarProyecto($this, $this->sector()->nivel->proyecto);

        return true;
    }

    public function rules(): array
    {
        $sector = $this->sector();
        $ultimaGuia = DB::table('asignaciones as a')
            ->join('guias_remision as g', 'g.id', '=', 'a.guia_id')
            ->where('a.sector_id', $sector->id)
            ->max('g.fecha');

        return [
            'fecha_culminacion' => array_filter([
                'bail', 'required', 'date_format:Y-m-d',
                'before_or_equal:'.today(ReporteEatRequest::ZONA_HORARIA)->toDateString(),
                'after_or_equal:'.$sector->nivel->proyecto->fecha_inicio->toDateString(),
                $ultimaGuia ? 'after_or_equal:'.substr((string) $ultimaGuia, 0, 10) : null,
            ]),
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_culminacion.required' => 'Ingrese la fecha de culminación',
            'fecha_culminacion.date_format' => 'Ingrese una fecha válida',
            'fecha_culminacion.before_or_equal' => 'La fecha de culminación no puede ser posterior a hoy',
            'fecha_culminacion.after_or_equal' => 'La fecha de culminación no puede ser anterior al inicio del proyecto ni a la última guía del sector',
        ];
    }

    private function sector(): Sector
    {
        return $this->route('sector');
    }
}
