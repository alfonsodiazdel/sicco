<?php

namespace App\Http\Requests\Usuarios;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registro (POST) y actualización (PUT) de un usuario, con las reglas de la pantalla. */
class GuardarUsuarioRequest extends FormRequest
{
    public function rules(): array
    {
        $usuario = $this->route('usuario');
        $actual = $usuario instanceof User ? $usuario : null;

        return [
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            // Como en la pantalla: el dominio debe llevar punto (nombre@empresa.com).
            'email' => ['bail', 'required', 'string', 'max:255', 'email:rfc', 'regex:/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/',
                Rule::unique('users', 'email')->ignore($actual)],
            'rol' => ['bail', 'required', 'string', Rule::enum(Rol::class), Rule::exists('roles', 'clave')],
            'proyecto_ids' => ['required', 'array', 'min:1'],
            'proyecto_ids.*' => ['bail', 'integer', 'distinct', Rule::exists('proyectos', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este dato es requerido',
            'string' => 'Debe ser un texto',
            'max' => 'Debe tener como máximo :max caracteres',
            'email.email' => 'Ingrese un correo válido, por ejemplo nombre@empresa.com.',
            'email.regex' => 'Ingrese un correo válido, por ejemplo nombre@empresa.com.',
            'email.unique' => 'El correo ya está registrado',
            'rol.enum' => 'Seleccione un rol válido',
            'rol.exists' => 'Seleccione un rol válido',
            'proyecto_ids.array' => 'Seleccione al menos un proyecto',
            'proyecto_ids.min' => 'Este dato es requerido',
            'proyecto_ids.*.integer' => 'Seleccione un proyecto válido',
            'proyecto_ids.*.distinct' => 'El proyecto está repetido',
            'proyecto_ids.*.exists' => 'El proyecto seleccionado no existe',
        ];
    }
}
