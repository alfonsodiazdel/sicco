<?php

namespace Database\Seeders;

use App\Enums\Rol as RolEnum;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Un usuario por rol para la demo en el servidor: <clave_del_rol>@sicco.app.
 * No usa factories (Faker no se instala con --no-dev). La contraseña temporal
 * sale de DEMO_PASSWORD y el sistema obliga a cambiarla en el primer ingreso.
 *
 * php artisan db:seed --class=UsuarioDemoProduccionSeeder --force
 */
class UsuarioDemoProduccionSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) config('sicco.demo_password');

        $validacion = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);
        if ($validacion->fails()) {
            throw new RuntimeException('DEMO_PASSWORD no es válida: '.$validacion->errors()->first('password'));
        }

        $this->call(RolSeeder::class);

        foreach (RolEnum::cases() as $rol) {
            // firstOrCreate: volver a ejecutarlo no pisa contraseñas ya cambiadas.
            $usuario = User::firstOrCreate(['email' => "{$rol->value}@sicco.app"], [
                'rol_id' => Rol::where('clave', $rol)->value('id'),
                'nombres' => $rol->nombre(),
                'apellidos' => 'Demo',
                'password' => $password,
                'activo' => true,
                'debe_cambiar_password' => true,
            ]);

            $this->command?->line(($usuario->wasRecentlyCreated ? 'Creado: ' : 'Ya existía: ').$usuario->email);
        }
    }
}
