<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CambiarPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string', 'max:255', 'current_password:web'],
            // bcrypt ignora lo que pasa de 72 bytes.
            'password' => ['required', 'string', 'max:72', 'confirmed', 'different:password_actual', Password::defaults()],
        ];
    }

    public function attributes(): array
    {
        return [
            'password_actual' => 'contraseña actual',
            'password' => 'nueva contraseña',
        ];
    }
}
