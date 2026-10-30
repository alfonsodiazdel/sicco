<?php

namespace App\Http\Requests\Usuarios;

use Illuminate\Foundation\Http\FormRequest;

/** Desactivar o reactivar un usuario (no se eliminan usuarios). */
class CambiarEstadoUsuarioRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'boolean' => 'Indique si el usuario queda activo o inactivo',
        ];
    }
}
