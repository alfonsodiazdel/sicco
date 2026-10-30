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

it('rechaza una contraseña actual de más de 255 caracteres', function () {
    $user = User::factory()->create();
    $hashAntes = $user->password;

    $this->actingAs($user)->putJson('/api/v1/auth/password', [
        'password_actual' => str_repeat('a', 256),
        'password' => 'NuevaClave123',
        'password_confirmation' => 'NuevaClave123',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['password_actual' => 'El campo contraseña actual no debe ser mayor que 255 caracteres.']);

    expect($user->fresh()->password)->toBe($hashAntes);
});

it('acepta una contraseña nueva de 72 caracteres y rechaza una de 73, el límite de bcrypt', function () {
    $user = User::factory()->create();
    $hashAntes = $user->password;
    $clave73 = str_repeat('a', 71).'12';

    $this->actingAs($user)->putJson('/api/v1/auth/password', [
        'password_actual' => 'password',
        'password' => $clave73,
        'password_confirmation' => $clave73,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => 'El campo nueva contraseña no debe ser mayor que 72 caracteres.']);
    expect($user->fresh()->password)->toBe($hashAntes);

    $clave72 = substr($clave73, 1);
    $this->actingAs($user)->putJson('/api/v1/auth/password', [
        'password_actual' => 'password',
        'password' => $clave72,
        'password_confirmation' => $clave72,
    ])->assertOk();
    expect(Hash::check($clave72, $user->fresh()->password))->toBeTrue();
});
