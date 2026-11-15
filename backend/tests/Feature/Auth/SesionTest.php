<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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

it('rechaza un correo de más de 255 caracteres sin intentar el acceso', function () {
    // Correo válido de 253 caracteres: parte local de 64 y etiquetas de dominio de 61.
    $dominio = str_repeat('b', 61).'.'.str_repeat('c', 61).'.'.str_repeat('d', 61).'.pe';
    $correo253 = str_repeat('a', 64).'@'.$dominio;

    $this->postJson('/api/v1/auth/login', ['email' => 'xxx'.$correo253, 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'El campo correo electrónico no debe ser mayor que 255 caracteres.']);
    $this->assertGuest();

    $this->postJson('/api/v1/auth/login', ['email' => $correo253, 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Correo o contraseña incorrectos.']);
});

it('rechaza una contraseña de más de 255 caracteres sin intentar el acceso', function () {
    User::factory()->create(['email' => 'ana@sicco.test']);

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@sicco.test', 'password' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => 'El campo contraseña no debe ser mayor que 255 caracteres.'])
        ->assertJsonMissingValidationErrors(['email']);
    $this->assertGuest();

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@sicco.test', 'password' => str_repeat('a', 255)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Correo o contraseña incorrectos.'])
        ->assertJsonMissingValidationErrors(['password']);
});

describe('captcha Turnstile', function () {
    beforeEach(function () {
        config([
            'services.turnstile.enabled' => true,
            'services.turnstile.secret' => 'secreto',
            'services.turnstile.hostnames' => ['sicco.test'],
        ]);
        User::factory()->create(['email' => 'ana@sicco.test']);
    });

    $login = fn (array $extra = []) => test()->postJson('/api/v1/auth/login', [
        'email' => 'ana@sicco.test',
        'password' => 'password',
        ...$extra,
    ]);

    it('exige el captcha cuando está activo', function () use ($login) {
        Http::fake();

        $login()->assertUnprocessable()->assertJsonValidationErrors(['captcha']);

        Http::assertNothingSent();
        $this->assertGuest();
    });

    it('inicia sesión con un token aprobado por Cloudflare', function () use ($login) {
        Http::fake(['challenges.cloudflare.com/*' => Http::response([
            'success' => true, 'action' => 'login', 'hostname' => 'sicco.test',
        ])]);

        $login(['captcha' => 'token-valido'])->assertOk();

        Http::assertSent(fn ($request) => $request['secret'] === 'secreto'
            && $request['response'] === 'token-valido');
        $this->assertAuthenticated();
    });

    it('rechaza el token', function (array $respuesta) use ($login) {
        Http::fake(['challenges.cloudflare.com/*' => Http::response($respuesta)]);

        $login(['captcha' => 'token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captcha' => 'No se pudo completar la verificación de seguridad. Inténtelo de nuevo.']);

        $this->assertGuest();
    })->with([
        'no aprobado' => [['success' => false, 'error-codes' => ['invalid-input-response']]],
        'otra acción' => [['success' => true, 'action' => 'registro', 'hostname' => 'sicco.test']],
        'otro hostname' => [['success' => true, 'action' => 'login', 'hostname' => 'evil.example']],
    ]);

    it('rechaza el token si Cloudflare no responde', function () use ($login) {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $login(['captcha' => 'token'])->assertUnprocessable()->assertJsonValidationErrors(['captcha']);
        $this->assertGuest();
    });
});
