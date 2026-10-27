<?php

namespace Database\Seeders;

use App\Enums\Rol as RolEnum;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Un usuario de prueba por rol. SOLO para desarrollo local.
 * Correo: <clave_del_rol>@sicco.test · Contraseña: password
 */
class UsuarioDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RolEnum::cases() as $rol) {
            User::factory()->rol($rol)->create([
                'nombres' => $rol->nombre(),
                'apellidos' => 'Demo',
                'email' => "{$rol->value}@sicco.test",
            ]);
        }
    }
}
