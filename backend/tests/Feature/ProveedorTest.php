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

function datosProveedorCompleto(array $cambios = []): array
{
    return [
        'ruc' => '20100030595',
        'razon_social' => 'UNICON S.A.',
        'contacto_nombre' => 'Rosa Quispe',
        'contacto_telefono' => '987654321',
        'contacto_correo' => 'rosa@unicon.pe',
        'correo_facturacion' => 'facturacion@unicon.pe',
        'condicion_pago' => '30',
        'direccion_fiscal' => 'Av. Industrial 123, Lima',
        'planta_despacho' => 'Planta Ate',
        ...$cambios,
    ];
}

describe('datos de contacto y condiciones comerciales', function () {
    it('registra un proveedor solo con RUC y razón social, activo por defecto', function () {
        $this->actingAs(oficinaTecnica())
            ->postJson('/api/v1/proveedores', ['ruc' => '20100030595', 'razon_social' => 'UNICON S.A.'])
            ->assertCreated()
            ->assertJsonPath('ruc', '20100030595')
            ->assertJsonPath('contacto_nombre', null)
            ->assertJsonPath('condicion_pago', null)
            ->assertJsonPath('activo', true);
    });

    it('guarda y devuelve los campos nuevos', function () {
        $this->actingAs(oficinaTecnica())
            ->postJson('/api/v1/proveedores', datosProveedorCompleto())
            ->assertCreated()
            ->assertJsonPath('contacto_telefono', '987654321')
            ->assertJsonPath('correo_facturacion', 'facturacion@unicon.pe')
            ->assertJsonPath('condicion_pago', '30')
            ->assertJsonPath('planta_despacho', 'Planta Ate');

        $this->assertDatabaseHas('proveedores', ['ruc' => '20100030595', 'contacto_nombre' => 'Rosa Quispe', 'activo' => true]);
    });

    it('valida los campos nuevos con mensajes en español', function (array $cambios, string $campo, string $mensaje) {
        $this->actingAs(oficinaTecnica())
            ->postJson('/api/v1/proveedores', datosProveedorCompleto($cambios))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$campo => $mensaje]);
    })->with([
        'teléfono de 8 dígitos' => [['contacto_telefono' => '98765432'], 'contacto_telefono', 'El teléfono debe tener 9 dígitos y empezar con 9.'],
        'teléfono que no empieza con 9' => [['contacto_telefono' => '887654321'], 'contacto_telefono', 'El teléfono debe tener 9 dígitos y empezar con 9.'],
        'correo de contacto inválido' => [['contacto_correo' => 'rosa'], 'contacto_correo', 'Ingrese un correo válido.'],
        'correo de facturación inválido' => [['correo_facturacion' => 'a@'], 'correo_facturacion', 'Ingrese un correo válido.'],
        'condición de pago fuera de la lista' => [['condicion_pago' => '45'], 'condicion_pago', 'La condición de pago debe ser contado o crédito a 30, 60 o 90 días.'],
        'nombre de contacto de 151 caracteres' => [['contacto_nombre' => str_repeat('a', 151)], 'contacto_nombre', 'El nombre del contacto no puede superar los 150 caracteres.'],
        'dirección fiscal de 251 caracteres' => [['direccion_fiscal' => str_repeat('a', 251)], 'direccion_fiscal', 'La dirección fiscal no puede superar los 250 caracteres.'],
        'planta de despacho de 151 caracteres' => [['planta_despacho' => str_repeat('a', 151)], 'planta_despacho', 'La planta de despacho no puede superar los 150 caracteres.'],
    ]);

    it('acepta contado y crédito a 60 y 90 días', function (string $condicion) {
        $this->actingAs(oficinaTecnica())
            ->postJson('/api/v1/proveedores', datosProveedorCompleto(['condicion_pago' => $condicion]))
            ->assertCreated()
            ->assertJsonPath('condicion_pago', $condicion);
    })->with(['contado', '60', '90']);

    it('desactiva y reactiva un proveedor', function () {
        $proveedor = Proveedor::factory()->create();
        $this->actingAs(oficinaTecnica());

        $this->putJson("/api/v1/proveedores/{$proveedor->id}", ['ruc' => $proveedor->ruc, 'razon_social' => $proveedor->razon_social, 'activo' => false])
            ->assertOk()->assertJsonPath('activo', false);
        $this->putJson("/api/v1/proveedores/{$proveedor->id}", ['ruc' => $proveedor->ruc, 'razon_social' => $proveedor->razon_social, 'activo' => true])
            ->assertOk()->assertJsonPath('activo', true);
    });

    it('filtra por activos para los combos', function () {
        Proveedor::factory()->create(['activo' => true]);
        Proveedor::factory()->create(['activo' => false]);

        $this->actingAs(oficinaTecnica())->getJson('/api/v1/proveedores')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/proveedores?activo=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.activo', true);
    });

    it('no expone el contacto a los roles sin permiso de proveedores', function (Rol $rol) {
        $proveedor = Proveedor::factory()->create(datosProveedorCompleto(['ruc' => '20100030595']));
        $this->actingAs(User::factory()->rol($rol)->create());

        foreach (['/api/v1/proveedores', "/api/v1/proveedores/{$proveedor->id}"] as $url) {
            $cuerpo = $this->getJson($url)->assertOk()->json();
            $fila = $cuerpo['data'][0] ?? $cuerpo;
            expect($fila)->toHaveKeys(['id', 'ruc', 'razon_social'])
                ->not->toHaveKeys(['contacto_nombre', 'contacto_telefono', 'contacto_correo', 'correo_facturacion', 'condicion_pago', 'direccion_fiscal', 'planta_despacho']);
        }
    })->with([Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia]);

    it('Oficina técnica y el administrador sí ven el contacto', function (Rol $rol) {
        $proveedor = Proveedor::factory()->create(datosProveedorCompleto());

        $this->actingAs(User::factory()->rol($rol)->create())
            ->getJson("/api/v1/proveedores/{$proveedor->id}")
            ->assertOk()
            ->assertJsonPath('contacto_correo', 'rosa@unicon.pe');
    })->with([Rol::OficinaTecnica, Rol::Administrador]);
});
