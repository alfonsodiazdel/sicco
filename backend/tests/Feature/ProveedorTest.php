<?php

use App\Enums\Rol;
use App\Models\Proveedor;
use App\Models\User;

function oficinaTecnica(): User
{
    return User::factory()->rol(Rol::OficinaTecnica)->create();
}

it('lista los proveedores paginados y permite buscar por RUC o razón social', function () {
    Proveedor::factory()->create(['ruc' => '20100030595', 'razon_social' => 'UNICON S.A.']);
    Proveedor::factory()->create(['ruc' => '20512345678', 'razon_social' => 'Concremax S.A.']);

    $this->actingAs(oficinaTecnica())
        ->getJson('/api/v1/proveedores')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);

    $this->getJson('/api/v1/proveedores?buscar=unicon')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ruc', '20100030595');

    $this->getJson('/api/v1/proveedores?buscar=2051')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.razon_social', 'Concremax S.A.');
});

it('registra un proveedor', function () {
    $this->actingAs(oficinaTecnica())
        ->postJson('/api/v1/proveedores', ['ruc' => ' 20100030595 ', 'razon_social' => 'UNICON S.A.'])
        ->assertCreated()
        ->assertJsonPath('ruc', '20100030595')
        ->assertJsonPath('razon_social', 'UNICON S.A.');

    expect(Proveedor::where('ruc', '20100030595')->exists())->toBeTrue();
});

it('valida los datos del proveedor', function (array $datos, string $campo) {
    Proveedor::factory()->create(['ruc' => '20100030595']);

    $this->actingAs(oficinaTecnica())
        ->postJson('/api/v1/proveedores', $datos)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($campo);
})->with([
    'RUC vacío' => [['ruc' => '', 'razon_social' => 'X'], 'ruc'],
    'RUC corto' => [['ruc' => '2010003059', 'razon_social' => 'X'], 'ruc'],
    'RUC con letras' => [['ruc' => '2010003059A', 'razon_social' => 'X'], 'ruc'],
    'RUC con prefijo inválido' => [['ruc' => '30100030595', 'razon_social' => 'X'], 'ruc'],
    'RUC duplicado' => [['ruc' => '20100030595', 'razon_social' => 'X'], 'ruc'],
    'razón social vacía' => [['ruc' => '20512345678', 'razon_social' => ''], 'razon_social'],
]);

it('actualiza un proveedor conservando su propio RUC', function () {
    $proveedor = Proveedor::factory()->create(['ruc' => '20100030595']);

    $this->actingAs(oficinaTecnica())
        ->putJson("/api/v1/proveedores/{$proveedor->id}", ['ruc' => '20100030595', 'razon_social' => 'Nuevo nombre'])
        ->assertOk()
        ->assertJsonPath('razon_social', 'Nuevo nombre');
});

it('elimina un proveedor', function () {
    $proveedor = Proveedor::factory()->create();

    $this->actingAs(oficinaTecnica())
        ->deleteJson("/api/v1/proveedores/{$proveedor->id}")
        ->assertNoContent();

    expect(Proveedor::find($proveedor->id))->toBeNull();
});

it('permite consultar proveedores a todos los roles', function (Rol $rol) {
    $proveedor = Proveedor::factory()->create();

    $this->actingAs(User::factory()->rol($rol)->create())
        ->getJson("/api/v1/proveedores/{$proveedor->id}")
        ->assertOk()
        ->assertJsonPath('id', $proveedor->id);
})->with(Rol::cases());

it('solo Oficina técnica gestiona proveedores', function (Rol $rol) {
    $proveedor = Proveedor::factory()->create();
    $this->actingAs(User::factory()->rol($rol)->create());

    $this->postJson('/api/v1/proveedores', ['ruc' => '20512345678', 'razon_social' => 'X'])
        ->assertForbidden()
        ->assertJsonPath('code', 'sin_permiso');
    $this->putJson("/api/v1/proveedores/{$proveedor->id}", ['ruc' => '20512345678', 'razon_social' => 'X'])
        ->assertForbidden();
    $this->deleteJson("/api/v1/proveedores/{$proveedor->id}")->assertForbidden();
})->with([Rol::Administrador, Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia]);
