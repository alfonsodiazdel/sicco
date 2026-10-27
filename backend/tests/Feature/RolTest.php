<?php

use App\Enums\Rol;
use App\Models\User;
use Database\Seeders\RolSeeder;

it('crea los cinco roles del documento', function () {
    $this->seed(RolSeeder::class);

    expect(App\Models\Rol::pluck('clave')->map->value->all())
        ->toEqualCanonicalizing(array_column(Rol::cases(), 'value'));
});

it('permite al administrador listar los roles', function () {
    $this->seed(RolSeeder::class);

    $this->actingAs(User::factory()->rol(Rol::Administrador)->create())
        ->getJson('/api/v1/roles')
        ->assertOk()
        ->assertJsonCount(5);
});

it('prohíbe listar roles a los demás perfiles', function (Rol $rol) {
    $this->actingAs(User::factory()->rol($rol)->create())
        ->getJson('/api/v1/roles')
        ->assertForbidden()
        ->assertJsonPath('code', 'sin_permiso');
})->with([
    Rol::Almacenero,
    Rol::IngenieroProduccion,
    Rol::OficinaTecnica,
    Rol::Gerencia,
]);
