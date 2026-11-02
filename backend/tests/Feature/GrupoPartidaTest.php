<?php

use App\Enums\Rol;
use App\Models\GrupoPartida;
use App\Models\User;
use Database\Seeders\GrupoPartidaSeeder;

function grupo(array $atributos = []): GrupoPartida
{
    return GrupoPartida::create([
        'nombre' => 'Cimentación',
        'partida_control' => '2.02.02',
        'orden' => 2,
        ...$atributos,
    ]);
}

function datosGrupo(array $cambios = []): array
{
    return ['nombre' => 'Obras exteriores', 'partida_control' => '2.02.02', 'orden' => 8, ...$cambios];
}

describe('listar', function () {
    it('devuelve el catálogo por orden, solo con los campos de la fila', function () {
        grupo(['nombre' => 'Cisterna', 'orden' => 3]);
        $muros = grupo(['nombre' => 'Muros anclados', 'orden' => 1]);

        $respuesta = $this->actingAs(conRol(Rol::OficinaTecnica))->getJson('/api/v1/grupos-partida')->assertOk();

        expect($respuesta->json('*.nombre'))->toBe(['Muros anclados', 'Cisterna']);
        $respuesta->assertJsonPath('0', [
            'id' => $muros->id,
            'nombre' => 'Muros anclados',
            'partida_control' => '2.02.02',
            'orden' => 1,
        ]);
    });

    it('deja consultar al administrador y prohíbe a los demás perfiles', function () {
        $this->actingAs(User::factory()->rol(Rol::Administrador)->create())
            ->getJson('/api/v1/grupos-partida')
            ->assertOk();

        foreach ([Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia] as $rol) {
            $this->actingAs(User::factory()->rol($rol)->create())
                ->getJson('/api/v1/grupos-partida')
                ->assertForbidden()
                ->assertJsonPath('code', 'sin_permiso');
        }
    });
});

describe('crear y editar', function () {
    it('registra un grupo y lo devuelve', function () {
        $this->actingAs(conRol(Rol::OficinaTecnica))->postJson('/api/v1/grupos-partida', datosGrupo(['nombre' => '  Obras exteriores ']))
            ->assertCreated()
            ->assertJsonPath('nombre', 'Obras exteriores')
            ->assertJsonPath('partida_control', '2.02.02')
            ->assertJsonPath('orden', 8);

        $this->assertDatabaseHas('grupos_partida', datosGrupo());
    });

    it('exige los tres campos', function () {
        $this->actingAs(conRol(Rol::OficinaTecnica))->postJson('/api/v1/grupos-partida', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nombre' => 'Este dato es requerido',
                'partida_control' => 'Este dato es requerido',
                'orden' => 'Este dato es requerido',
            ]);
    });

    it('no repite el nombre, sin distinguir mayúsculas ni tildes', function (string $nombre) {
        grupo();

        $this->actingAs(conRol(Rol::OficinaTecnica))->postJson('/api/v1/grupos-partida', datosGrupo(['nombre' => $nombre]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre' => 'El grupo ya existe']);
    })->with(['CIMENTACIÓN', 'cimentacion']);

    it('valida la partida de control y el orden', function () {
        $this->actingAs(conRol(Rol::OficinaTecnica))->postJson('/api/v1/grupos-partida', datosGrupo([
            'partida_control' => '2.02-A',
            'orden' => 0,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'partida_control' => 'Use solo números separados por puntos',
                'orden' => 'Ingrese un valor mayor a cero',
            ]);
    });

    it('edita un grupo y conserva su propio nombre sin marcarlo como repetido', function () {
        $g = grupo();

        $this->actingAs(conRol(Rol::OficinaTecnica))
            ->putJson("/api/v1/grupos-partida/{$g->id}", ['nombre' => 'Cimentación', 'partida_control' => '2.03.01', 'orden' => 9])
            ->assertOk()
            ->assertJsonPath('partida_control', '2.03.01')
            ->assertJsonPath('orden', 9);
    });

    it('solo Oficina técnica registra, edita o elimina', function (Rol $rol) {
        $g = grupo();
        $usuario = User::factory()->rol($rol)->create();

        $this->actingAs($usuario)->postJson('/api/v1/grupos-partida', datosGrupo())->assertForbidden();
        $this->actingAs($usuario)->putJson("/api/v1/grupos-partida/{$g->id}", datosGrupo())->assertForbidden();
        $this->actingAs($usuario)->deleteJson("/api/v1/grupos-partida/{$g->id}")->assertForbidden();

        expect(GrupoPartida::count())->toBe(1);
    })->with([Rol::Administrador, Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia]);
});

describe('eliminar', function () {
    it('elimina un grupo sin partidas', function () {
        $g = grupo();

        $this->actingAs(conRol(Rol::OficinaTecnica))->deleteJson("/api/v1/grupos-partida/{$g->id}")->assertNoContent();

        $this->assertModelMissing($g);
    });

    it('rechaza eliminar un grupo con partidas en algún proyecto', function () {
        $g = grupo();
        obra()->partidas()->create(datosPartida(['grupo_id' => $g->id]));

        $this->actingAs(conRol(Rol::OficinaTecnica))->deleteJson("/api/v1/grupos-partida/{$g->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'grupo_en_uso')
            ->assertJsonPath('message', 'El grupo tiene partidas registradas; reasígnelas antes de eliminarlo.');

        $this->assertModelExists($g);
    });
});

it('el seeder carga el catálogo inicial y no pisa los cambios hechos después', function () {
    $this->seed(GrupoPartidaSeeder::class);
    GrupoPartida::where('nombre', 'Cisterna')->update(['partida_control' => '2.03.01']);
    $this->seed(GrupoPartidaSeeder::class);

    expect(GrupoPartida::orderBy('orden')->pluck('nombre')->all())->toBe([
        'Muros anclados', 'Cimentación', 'Cisterna', 'Verticales sótanos',
        'Horizontales sótanos', 'Verticales superestructura', 'Horizontales superestructura',
    ]);
    expect(GrupoPartida::where('nombre', 'Cisterna')->value('partida_control'))->toBe('2.03.01');
});
