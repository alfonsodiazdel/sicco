<?php

namespace App\Http\Requests\Proyectos;

use App\Enums\EstadoProyecto;
use App\Enums\Rol;
use App\Models\Proyecto;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registro (POST) y actualización (PUT) de un proyecto, con las reglas de la pantalla. */
class GuardarProyectoRequest extends FormRequest
{
    public function rules(): array
    {
        $proyecto = $this->route('proyecto');
        $actual = $proyecto instanceof Proyecto ? $proyecto : null;

        $reglas = [
            'codigo' => ['required', 'string', 'max:20', Rule::unique('proyectos', 'codigo')->ignore($actual)],
            'nombre' => ['required', 'string', 'max:120'],
            'direccion' => ['required', 'string', 'max:150'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after:fecha_inicio'],
            'ingeniero_id' => ['required', 'integer', Rule::exists('users', 'id')->where(
                // Al editar se conserva el responsable aunque haya quedado inactivo.
                fn (Builder $q) => $q->where(fn (Builder $q) => $q
                    ->where('activo', true)
                    ->whereIn('rol_id', fn (Builder $q) => $q->select('id')->from('roles')
                        ->where('clave', Rol::IngenieroProduccion->value))
                    ->orWhere('id', $actual?->ingeniero_id)),
            )],
        ];

        if ($actual) {
            $reglas['estado'] = ['sometimes', Rule::enum(EstadoProyecto::class)];
        }

        return $reglas;
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'max' => 'Debe tener como máximo :max caracteres',
            'codigo.unique' => 'El código de proyecto ya existe',
            'fecha_fin.after' => 'La fecha de fin debe ser posterior a la de inicio',
        ];
    }

    public function attributes(): array
    {
        return [
            'fecha_inicio' => 'fecha de inicio',
            'fecha_fin' => 'fecha de fin',
            'ingeniero_id' => 'ingeniero responsable',
        ];
    }
}
