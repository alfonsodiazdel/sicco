<?php

use App\Models\User;

// La SPA envía estas cabeceras; con ellas Sanctum habilita la sesión por cookie.
beforeEach(fn () => $this->withHeaders([
    'Origin' => 'http://localhost:5173',
    'Referer' => 'http://localhost:5173/',
]));

it('inicia sesión con credenciales válidas y registra el acceso', function () {
    $user = User::factory()->create(['email' => 'ana@sicco.test']);

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@sicco.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('email', 'ana@sicco.test')
        ->assertJsonPath('rol.clave', 'almacenero');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->ultimo_acceso_at)->not->toBeNull();
});

it('rechaza credenciales incorrectas', function () {
    User::factory()->create(['email' => 'ana@sicco.test']);

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@sicco.test', 'password' => 'otra'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Correo o contraseña incorrectos.']);

    $this->assertGuest();
});

it('valida los campos requeridos', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

it('no permite ingresar a un usuario inactivo', function () {
    User::factory()->inactivo()->create(['email' => 'ana@sicco.test']);

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@sicco.test', 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('code', 'usuario_inactivo');

    $this->assertGuest();
});

it('bloquea temporalmente tras 5 intentos fallidos', function () {
    User::factory()->create(['email' => 'ana@sicco.test']);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@sicco.test', 'password' => 'otra']);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@sicco.test', 'password' => 'password'])
        ->assertTooManyRequests();
});

it('devuelve el usuario autenticado', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonMissingPath('password');
});

it('exige sesión para consultar el usuario', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('cierra la sesión', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    $this->assertGuest('web');
});

it('expulsa a un usuario desactivado con la sesión abierta', function () {
    $user = User::factory()->inactivo()->create();

    $this->actingAs($user)->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'usuario_inactivo');
});
