<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CambiarPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:password_actual', Password::defaults()],
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
