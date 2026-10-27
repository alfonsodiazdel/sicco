<?php

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('cambia la contraseña y libera el acceso al sistema', function () {
    $user = User::factory()->rol(Rol::Administrador)->conPasswordTemporal()->create();

    $this->actingAs($user)->getJson('/api/v1/roles')
        ->assertForbidden()
        ->assertJsonPath('code', 'debe_cambiar_password');

    $this->actingAs($user)->putJson('/api/v1/auth/password', [
        'password_actual' => 'password',
        'password' => 'NuevaClave123',
        'password_confirmation' => 'NuevaClave123',
    ])->assertOk();

    $user->refresh();
    expect(Hash::check('NuevaClave123', $user->password))->toBeTrue()
        ->and($user->debe_cambiar_password)->toBeFalse();

    $this->actingAs($user)->getJson('/api/v1/roles')->assertOk();
});

it('rechaza una contraseña actual incorrecta o una nueva débil', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/auth/password', [
        'password_actual' => 'incorrecta',
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['password_actual', 'password']);
});
