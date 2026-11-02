<?php

namespace App\Http\Requests;

use App\Models\Nivel;
use App\Models\Sector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registro (POST) y actualización (PUT) de un sector de vaciado (CU05). El tope de volumen frente a
 * la partida se valida en el controlador, dentro de la transacción que bloquea la partida.
 */
class SectorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre]);
    }

    public function rules(): array
    {
        $sector = $this->route('sector');
        $nivel = $sector instanceof Sector ? $sector->nivel : $this->route('nivel');
        $nivel = $nivel instanceof Nivel ? $nivel : null;

        return [
            'nombre' => ['required', 'string', 'max:40', Rule::unique('sectores', 'nombre')
                ->where('nivel_id', $nivel?->id)
                ->ignore($sector instanceof Sector ? $sector : null)],
            // Solo una partida del mismo proyecto que el nivel.
            'presupuesto_id' => ['required', 'integer', Rule::exists('partidas', 'id')
                ->where('proyecto_id', $nivel?->proyecto_id)],
            'volumen_presup_m3' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'numeric' => 'Este dato es requerido',
            'max' => 'Debe tener como máximo :max caracteres',
            'nombre.unique' => __('sicco.sectores.duplicado'),
            'presupuesto_id.exists' => __('sicco.sectores.partida_ajena'),
            'volumen_presup_m3.decimal' => 'Ingrese como máximo dos decimales',
            'volumen_presup_m3.gt' => 'Ingrese un valor mayor a cero',
            'volumen_presup_m3.max' => 'El valor es demasiado grande',
        ];
    }
}
