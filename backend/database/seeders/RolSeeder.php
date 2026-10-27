<?php

namespace Database\Seeders;

use App\Enums\Rol as RolEnum;
use App\Models\Rol;
use Illuminate\Database\Seeder;

/**
 * Roles fijos del sistema. Se ejecuta también en producción.
 */
class RolSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RolEnum::cases() as $rol) {
            Rol::updateOrCreate(
                ['clave' => $rol->value],
                ['nombre' => $rol->nombre(), 'descripcion' => $rol->descripcion()],
            );
        }
    }
}
