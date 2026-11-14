<?php

use App\Enums\Rol;
use App\Models\Resistencia;
use App\Models\User;

const RESISTENCIA_EN_USO = 'Esta resistencia está en uso: solo se puede desactivar.';

function datosResistencia(array $cambios = []): array
{
    return ['fc_kg_cm2' => 350, 'nombre' => "f'c 350 kg/cm²", ...$cambios];
}

/** Resistencia usada por una partida, una guía o una línea de factura. */
function resistenciaUsadaPor(string $donde, int $fc = 210): Resistencia
{
    $r = resistencia($fc);
    $p = obra();
    match ($donde) {
        'partida' => $p->partidas()->create(datosPartida(['resistencia_id' => $r->id])),
        'guia' => guiaCorte($p, 'T001-1', '2026-11-02', $r, 6, null),
        'factura' => facturaCorte($p, 'F001-1', '2026-11-02', [[$r, 400]], 10, 4000),
    };

    return $r;
}

describe('listado', function () {
    it('GET /resistencias mantiene su forma y solo trae las activas', function () {
        resistencia(280);
        Resistencia::create(['fc_kg_cm2' => 140, 'nombre' => "f'c 140 kg/cm²", 'activo' => false]);

        $r = $this->actingAs(conRol(Rol::Almacenero))->getJson('/api/v1/resistencias')->assertOk();

        expect(array_keys($r->json('0')))->toBe(['id', 'fc_kg_cm2', 'nombre'])
            ->and(collect($r->json())->pluck('fc_kg_cm2')->all())->not->toContain(140);
    });

    it('el listado del mantenedor trae todas, con activo y en_uso, para Oficina técnica y administrador', function () {
        resistenciaUsadaPor('partida', 210);
        Resistencia::create(['fc_kg_cm2' => 140, 'nombre' => "f'c 140 kg/cm²", 'activo' => false]);

        $r = $this->actingAs(conRol(Rol::OficinaTecnica))->getJson('/api/v1/resistencias/todas')->assertOk();

        $f210 = collect($r->json())->firstWhere('fc_kg_cm2', 210);
        expect(array_keys($f210))->toEqualCanonicalizing(['id', 'fc_kg_cm2', 'nombre', 'activo', 'en_uso'])
            ->and($f210['en_uso'])->toBeTrue()
            ->and(collect($r->json())->firstWhere('fc_kg_cm2', 140)['activo'])->toBeFalse();
        $this->actingAs(User::factory()->rol(Rol::Administrador)->create())->getJson('/api/v1/resistencias/todas')->assertOk();
        $this->actingAs(conRol(Rol::Gerencia))->getJson('/api/v1/resistencias/todas')->assertForbidden();
    });
});

describe('crear', function () {
    it('crea una resistencia activa', function () {
        $this->actingAs(conRol(Rol::OficinaTecnica))->postJson('/api/v1/resistencias', datosResistencia())
            ->assertCreated()
            ->assertJsonPath('fc_kg_cm2', 350)
            ->assertJsonPath('nombre', "f'c 350 kg/cm²")
            ->assertJsonPath('activo', true);
    });

    it('valida contra las columnas de la tabla', function () {
        $oficina = conRol(Rol::OficinaTecnica);
        resistencia(280);

        $this->actingAs($oficina)->postJson('/api/v1/resistencias', ['fc_kg_cm2' => '', 'nombre' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fc_kg_cm2' => 'Este dato es requerido', 'nombre' => 'Este dato es requerido']);
        $this->actingAs($oficina)->postJson('/api/v1/resistencias', datosResistencia(['fc_kg_cm2' => 280]))
            ->assertUnprocessable()->assertJsonValidationErrors(['fc_kg_cm2' => 'La resistencia ya existe']);
        $this->actingAs($oficina)->postJson('/api/v1/resistencias', datosResistencia(['fc_kg_cm2' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors(['fc_kg_cm2' => 'Ingrese un valor mayor a cero']);
        $this->actingAs($oficina)->postJson('/api/v1/resistencias', datosResistencia(['fc_kg_cm2' => 65536]))
            ->assertUnprocessable()->assertJsonValidationErrors(['fc_kg_cm2' => 'Ingrese un valor menor o igual a 65535']);
        $this->actingAs($oficina)->postJson('/api/v1/resistencias', datosResistencia(['fc_kg_cm2' => 'abc']))
            ->assertUnprocessable()->assertJsonValidationErrors(['fc_kg_cm2' => 'Ingrese un número entero']);
        $this->actingAs($oficina)->postJson('/api/v1/resistencias', datosResistencia(['nombre' => str_repeat('a', 31)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'Debe tener como máximo 30 caracteres']);
    });
});

describe('editar y activar', function () {
    it('edita una que no está en uso', function () {
        $r = resistencia(175);

        $this->actingAs(conRol(Rol::OficinaTecnica))
            ->putJson("/api/v1/resistencias/{$r->id}", ['fc_kg_cm2' => 180, 'nombre' => "f'c 180 kg/cm²"])
            ->assertOk()->assertJsonPath('fc_kg_cm2', 180);
    });

    it('no edita una en uso (partida, guía o factura): solo se desactiva', function (string $donde) {
        $r = resistenciaUsadaPor($donde);

        $this->actingAs(conRol(Rol::OficinaTecnica))
            ->putJson("/api/v1/resistencias/{$r->id}", ['fc_kg_cm2' => 215, 'nombre' => "f'c 215 kg/cm²"])
            ->assertStatus(409)
            ->assertJsonPath('message', RESISTENCIA_EN_USO)
            ->assertJsonPath('code', 'resistencia_en_uso');
        expect($r->fresh()->fc_kg_cm2)->toBe(210);
    })->with(['partida', 'guia', 'factura']);

    it('desactiva y reactiva una en uso sin cambiar sus datos', function () {
        $r = resistenciaUsadaPor('guia');
        $oficina = conRol(Rol::OficinaTecnica);
        $datos = ['fc_kg_cm2' => 210, 'nombre' => $r->nombre];

        $this->actingAs($oficina)->putJson("/api/v1/resistencias/{$r->id}", [...$datos, 'activo' => false])
            ->assertOk()->assertJsonPath('activo', false);
        $this->actingAs($oficina)->putJson("/api/v1/resistencias/{$r->id}", [...$datos, 'activo' => true])
            ->assertOk()->assertJsonPath('activo', true);
    });
});

describe('eliminar', function () {
    it('elimina una que no está en uso', function () {
        $r = resistencia(175);

        $this->actingAs(conRol(Rol::OficinaTecnica))->deleteJson("/api/v1/resistencias/{$r->id}")->assertNoContent();
        $this->assertModelMissing($r);
    });

    it('no elimina una en uso: solo se desactiva', function (string $donde) {
        $r = resistenciaUsadaPor($donde);

        $this->actingAs(conRol(Rol::OficinaTecnica))->deleteJson("/api/v1/resistencias/{$r->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', RESISTENCIA_EN_USO)
            ->assertJsonPath('code', 'resistencia_en_uso');
        $this->assertModelExists($r);
    })->with(['partida', 'guia', 'factura']);
});

describe('permisos', function () {
    it('escribe solo Oficina técnica; los demás reciben 403', function () {
        $r = resistencia(175);

        foreach ([Rol::Administrador, Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia] as $rol) {
            $u = User::factory()->rol($rol)->create();
            $this->actingAs($u)->postJson('/api/v1/resistencias', datosResistencia())->assertForbidden();
            $this->actingAs($u)->putJson("/api/v1/resistencias/{$r->id}", datosResistencia())->assertForbidden();
            $this->actingAs($u)->deleteJson("/api/v1/resistencias/{$r->id}")->assertForbidden();
        }
    });
});
