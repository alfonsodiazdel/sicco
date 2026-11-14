<?php

use App\Enums\Rol;
use App\Models\ElementoEstructural;
use App\Models\Partida;
use App\Models\Resistencia;
use App\Models\User;

const ELEMENTOS_INICIALES = [
    'Zapata', 'Cimiento corrido', 'Columna', 'Placa', 'Viga', 'Losa aligerada', 'Losa maciza', 'Escalera',
];

function oficinaDeElementos(): User
{
    return conRol(Rol::OficinaTecnica);
}

describe('catálogo inicial y listado', function () {
    it('la migración deja los 8 elementos actuales, activos', function () {
        expect(ElementoEstructural::pluck('nombre')->all())->toEqualCanonicalizing(ELEMENTOS_INICIALES)
            ->and(ElementoEstructural::where('activo', false)->count())->toBe(0);
    });

    it('lista id, nombre, activo y si está en uso, para Oficina técnica y administrador', function () {
        $p = obra();
        $p->partidas()->create(datosPartida(['elemento' => 'Zapata']));

        $r = $this->actingAs(oficinaDeElementos())->getJson('/api/v1/elementos-estructurales/todos')->assertOk();

        expect($r->json())->toHaveCount(8);
        $zapata = collect($r->json())->firstWhere('nombre', 'Zapata');
        expect(array_keys($zapata))->toEqualCanonicalizing(['id', 'nombre', 'activo', 'en_uso'])
            ->and($zapata['en_uso'])->toBeTrue()
            ->and(collect($r->json())->firstWhere('nombre', 'Viga')['en_uso'])->toBeFalse();
        $this->actingAs(User::factory()->rol(Rol::Administrador)->create())
            ->getJson('/api/v1/elementos-estructurales/todos')->assertOk();
    });

    it('el listado para los combos trae solo los activos', function () {
        ElementoEstructural::where('nombre', 'Viga')->update(['activo' => false]);

        $r = $this->actingAs(conRol(Rol::Almacenero))->getJson('/api/v1/elementos-estructurales')->assertOk();

        expect($r->json('*.nombre'))->not->toContain('Viga')->toHaveCount(7);
    });
});

describe('crear y renombrar', function () {
    it('crea un elemento activo', function () {
        $this->actingAs(oficinaDeElementos())->postJson('/api/v1/elementos-estructurales', ['nombre' => 'Muro de contención'])
            ->assertCreated()
            ->assertJsonPath('nombre', 'Muro de contención')
            ->assertJsonPath('activo', true);
    });

    it('exige nombre, de 30 caracteres como máximo', function () {
        $this->actingAs(oficinaDeElementos())->postJson('/api/v1/elementos-estructurales', ['nombre' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'Este dato es requerido']);
        $this->actingAs(oficinaDeElementos())->postJson('/api/v1/elementos-estructurales', ['nombre' => str_repeat('a', 31)])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'Debe tener como máximo 30 caracteres']);
        $this->actingAs(oficinaDeElementos())->postJson('/api/v1/elementos-estructurales', ['nombre' => str_repeat('a', 30)])
            ->assertCreated();
    });

    it('rechaza un nombre repetido sin importar mayúsculas', function (string $nombre) {
        $this->actingAs(oficinaDeElementos())->postJson('/api/v1/elementos-estructurales', ['nombre' => $nombre])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre' => 'El elemento ya existe']);
    })->with(['zapata', 'ZAPATA']);

    it('renombra uno que no está en uso', function () {
        $viga = ElementoEstructural::where('nombre', 'Viga')->sole();

        $this->actingAs(oficinaDeElementos())->putJson("/api/v1/elementos-estructurales/{$viga->id}", ['nombre' => 'Viga peraltada'])
            ->assertOk()->assertJsonPath('nombre', 'Viga peraltada');
    });

    it('permite guardar el mismo nombre de la fila al editarla', function () {
        $viga = ElementoEstructural::where('nombre', 'Viga')->sole();

        $this->actingAs(oficinaDeElementos())->putJson("/api/v1/elementos-estructurales/{$viga->id}", ['nombre' => 'Viga'])
            ->assertOk();
    });

    it('no renombra uno en uso: solo se desactiva', function () {
        obra()->partidas()->create(datosPartida(['elemento' => 'Zapata']));
        $zapata = ElementoEstructural::where('nombre', 'Zapata')->sole();

        $this->actingAs(oficinaDeElementos())->putJson("/api/v1/elementos-estructurales/{$zapata->id}", ['nombre' => 'Zapata aislada'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Este elemento está en uso en partidas: solo se puede desactivar.')
            ->assertJsonPath('code', 'elemento_en_uso');
        expect($zapata->fresh()->nombre)->toBe('Zapata');
    });
});

describe('activar y desactivar', function () {
    it('desactiva y reactiva también uno en uso, sin tocar las partidas', function () {
        $partida = obra()->partidas()->create(datosPartida(['elemento' => 'Zapata']));
        $zapata = ElementoEstructural::where('nombre', 'Zapata')->sole();

        $this->actingAs(oficinaDeElementos())->putJson("/api/v1/elementos-estructurales/{$zapata->id}", ['nombre' => 'Zapata', 'activo' => false])
            ->assertOk()->assertJsonPath('activo', false);
        expect($partida->fresh()->elemento)->toBe('Zapata');

        $this->actingAs(oficinaDeElementos())->putJson("/api/v1/elementos-estructurales/{$zapata->id}", ['nombre' => 'Zapata', 'activo' => true])
            ->assertOk()->assertJsonPath('activo', true);
    });
});

describe('eliminar', function () {
    it('elimina uno que no está en uso', function () {
        $viga = ElementoEstructural::where('nombre', 'Viga')->sole();

        $this->actingAs(oficinaDeElementos())->deleteJson("/api/v1/elementos-estructurales/{$viga->id}")->assertNoContent();
        $this->assertModelMissing($viga);
    });

    it('no elimina uno en uso: solo se desactiva', function () {
        obra()->partidas()->create(datosPartida(['elemento' => 'Zapata']));
        $zapata = ElementoEstructural::where('nombre', 'Zapata')->sole();

        $this->actingAs(oficinaDeElementos())->deleteJson("/api/v1/elementos-estructurales/{$zapata->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Este elemento está en uso en partidas: solo se puede desactivar.')
            ->assertJsonPath('code', 'elemento_en_uso');
        $this->assertModelExists($zapata);
    });
});

describe('permisos', function () {
    it('escribe solo Oficina técnica; los demás reciben 403', function () {
        $viga = ElementoEstructural::where('nombre', 'Viga')->sole();

        foreach ([Rol::Administrador, Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia] as $rol) {
            $u = User::factory()->rol($rol)->create();
            $this->actingAs($u)->postJson('/api/v1/elementos-estructurales', ['nombre' => 'Nuevo'])->assertForbidden();
            $this->actingAs($u)->putJson("/api/v1/elementos-estructurales/{$viga->id}", ['nombre' => 'X'])->assertForbidden();
            $this->actingAs($u)->deleteJson("/api/v1/elementos-estructurales/{$viga->id}")->assertForbidden();
        }
    });

    it('el listado completo con inactivos y en_uso es solo de Oficina técnica y administrador', function () {
        foreach ([Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia] as $rol) {
            $this->actingAs(User::factory()->rol($rol)->create())
                ->getJson('/api/v1/elementos-estructurales/todos')->assertForbidden();
        }
    });
});

describe('la partida usa los elementos de la tabla', function () {
    it('acepta un elemento nuevo y activo, y rechaza uno inactivo o desconocido', function () {
        $p = obra();
        ElementoEstructural::create(['nombre' => 'Muro de contención']);
        ElementoEstructural::where('nombre', 'Viga')->update(['activo' => false]);

        $this->actingAs(asignadoA($p))->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida(['elemento' => 'Muro de contención']))
            ->assertCreated();
        foreach (['Viga', 'Inventado'] as $malo) {
            $this->actingAs(asignadoA($p))->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida(['elemento' => $malo]))
                ->assertUnprocessable()->assertJsonValidationErrors(['elemento' => 'Este dato es requerido']);
        }
    });

    it('al editar acepta el valor actual aunque el elemento esté inactivo, pero no otro inactivo', function () {
        $p = obra();
        $partida = $p->partidas()->create(datosPartida(['elemento' => 'Viga']));
        ElementoEstructural::whereIn('nombre', ['Viga', 'Placa'])->update(['activo' => false]);
        $u = asignadoA($p);

        $this->actingAs($u)->putJson("/api/v1/partidas/{$partida->id}", datosPartida(['elemento' => 'Viga', 'descripcion' => 'Editada']))
            ->assertOk();
        $this->actingAs($u)->putJson("/api/v1/partidas/{$partida->id}", datosPartida(['elemento' => 'Placa']))
            ->assertUnprocessable()->assertJsonValidationErrors(['elemento']);
    });

    it('al editar acepta la resistencia actual aunque esté inactiva, pero no otra inactiva', function () {
        $p = obra();
        $partida = $p->partidas()->create(datosPartida());
        $otra = resistencia(280);
        Resistencia::whereIn('id', [$partida->resistencia_id, $otra->id])->update(['activo' => false]);
        $u = asignadoA($p);

        $this->actingAs($u)->putJson("/api/v1/partidas/{$partida->id}", datosPartida(['resistencia_id' => $partida->resistencia_id, 'descripcion' => 'Editada']))
            ->assertOk();
        $this->actingAs($u)->putJson("/api/v1/partidas/{$partida->id}", datosPartida(['resistencia_id' => $otra->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['resistencia_id']);
    });

    it('sigue creando partidas con los elementos de siempre', function () {
        $p = obra();

        foreach (ELEMENTOS_INICIALES as $e) {
            $this->actingAs(asignadoA($p))->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida(['elemento' => $e]))
                ->assertCreated()->assertJsonPath('elemento', $e);
        }
        expect(Partida::count())->toBe(8);
    });
});
