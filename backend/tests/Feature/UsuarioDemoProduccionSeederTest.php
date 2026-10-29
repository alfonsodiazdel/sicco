<?php

use App\Enums\Rol;
use App\Models\User;
use Database\Seeders\UsuarioDemoProduccionSeeder;
use Illuminate\Support\Facades\Hash;

it('crea un usuario por rol con contraseña temporal obligatoria de cambiar', function () {
    config(['sicco.demo_password' => 'Temporal2026']);

    $this->seed(UsuarioDemoProduccionSeeder::class);

    expect(User::count())->toBe(5);

    foreach (Rol::cases() as $rol) {
        $usuario = User::where('email', "{$rol->value}@sicco.app")->firstOrFail();

        expect($usuario->rol->clave)->toBe($rol)
            ->and($usuario->activo)->toBeTrue()
            ->and($usuario->debe_cambiar_password)->toBeTrue()
            ->and(Hash::check('Temporal2026', $usuario->password))->toBeTrue();
    }
});

it('no pisa la contraseña de un usuario que ya la cambió', function () {
    config(['sicco.demo_password' => 'Temporal2026']);
    $this->seed(UsuarioDemoProduccionSeeder::class);

    User::where('email', 'gerencia@sicco.app')->first()
        ->update(['password' => 'Definitiva2026', 'debe_cambiar_password' => false]);

    $this->seed(UsuarioDemoProduccionSeeder::class);

    $gerencia = User::where('email', 'gerencia@sicco.app')->first();
    expect(User::count())->toBe(5)
        ->and(Hash::check('Definitiva2026', $gerencia->password))->toBeTrue()
        ->and($gerencia->debe_cambiar_password)->toBeFalse();
});

it('exige una DEMO_PASSWORD válida', function (?string $password) {
    config(['sicco.demo_password' => $password]);

    expect(fn () => $this->seed(UsuarioDemoProduccionSeeder::class))
        ->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
})->with([
    'sin definir' => null,
    'corta' => 'abc1',
    'sin números' => 'soloLetras',
]);
