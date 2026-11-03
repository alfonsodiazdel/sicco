<?php

namespace App\Http\Requests\Guias;

use App\Http\Controllers\Concerns\AutorizaProyecto;
use App\Models\GuiaRemision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CU09 pasos 3–5: el reparto completo de la guía en sectores. Reemplaza al anterior, así el mismo
 * formulario sirve para corregir una asignación. La suma frente al volumen de la guía se valida en
 * el controlador, dentro de la transacción que bloquea la guía.
 */
class AsignarGuiaRequest extends FormRequest
{
    use AutorizaProyecto;

    /** Antes de validar: a quien no tiene acceso a la guía no se le dice qué sectores existen. */
    public function authorize(): bool
    {
        $this->autorizarProyecto($this, $this->route('guia')->proyecto);

        return true;
    }

    public function rules(): array
    {
        $guia = $this->route('guia');
        $proyectoId = $guia instanceof GuiaRemision ? $guia->proyecto_id : null;

        return [
            'asignaciones' => ['required', 'array', 'min:1', 'max:50'],
            // Solo sectores del proyecto de la guía, cada uno una vez.
            'asignaciones.*.sector_id' => ['required', 'integer', 'distinct', Rule::exists('sectores', 'id')
                ->whereIn('nivel_id', fn ($q) => $q->select('id')->from('niveles')->where('proyecto_id', $proyectoId))],
            'asignaciones.*.volumen_m3' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'numeric' => 'Este dato es requerido',
            'integer' => 'Este dato es requerido',
            'asignaciones.min' => __('sicco.asignaciones.sin_sectores'),
            'asignaciones.max' => 'Debe tener como máximo :max sectores',
            'asignaciones.*.sector_id.distinct' => __('sicco.asignaciones.sector_repetido'),
            'asignaciones.*.sector_id.exists' => __('sicco.asignaciones.sector_ajeno'),
            'asignaciones.*.volumen_m3.decimal' => 'Ingrese como máximo dos decimales',
            'asignaciones.*.volumen_m3.gt' => 'Ingrese un valor mayor a cero',
            'asignaciones.*.volumen_m3.max' => 'El valor es demasiado grande',
        ];
    }
}
