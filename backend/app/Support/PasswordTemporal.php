<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Contraseña temporal del usuario nuevo; cumple Password::defaults() (letras y números). */
class PasswordTemporal
{
    public function generar(): string
    {
        return Str::password(12, symbols: false);
    }
}
