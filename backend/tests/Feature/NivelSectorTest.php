<?php

use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use App\Enums\Rol;
use App\Models\Asignacion;
use App\Models\GuiaRemision;
use App\Models\Nivel;
use App\Models\Partida;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\Sector;
use Database\Seeders\GrupoPartidaSeeder;
use Database\Seeders\NivelDemoSeeder;
use Database\Seeders\PartidaDemoSeeder;
use Database\Seeders\ProyectoDemoSeeder;
use Database\Seeders\ResistenciaSeeder;
use Database\Seeders\UsuarioDemoSeeder;
use Illuminate\Support\Carbon;

/** Proyecto con una partida de columnas de 180.50 m³ y un ingeniero asignado. */
function obraConColumnas(): array
{
    $proyecto = obra();
    $partida = $proyecto->partidas()->create(datosPartida([
        'descripcion' => "CONCRETO PREMEZ. F'C=280 kg/cm2 - COLUMNAS",
        'elemento' => 'Columna',
        'resistencia_id' => resistencia(280)->id,
        'metrado_m3' => 180.5,
    ]));

    return [$proyecto, $partida, asignadoA($proyecto, Rol::IngenieroProduccion)];
}

function nivel(Proyecto $proyecto, string $nombre = 'Piso 1', int $orden = 1): Nivel
{
    return $proyecto->niveles()->create(['nombre' => $nombre, 'orden' => $orden]);
}

function sector(Nivel $nivel, Partida $partida, float $volumen, string $nombre = 'Sector A'): Sector
{
    return $nivel->sectores()->create([
        'presupuesto_id' => $partida->id,
        'nombre' => $nombre,
        'volumen_presup_m3' => $volumen,
    ]);
}

describe('niveles', function () {
    it('lista los niveles del proyecto por orden', function () {
        [$p, , $ing] = obraConColumnas();
        nivel($p, 'Piso 1', 2);
        $sotano = nivel($p, 'Sótano 1', 1);
        nivel(obra(['codigo' => 'PRY-002']), 'Otro', 1);

        $this->actingAs($ing)->getJson("/api/v1/proyectos/{$p->id}/niveles")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0', ['id' => $sotano->id, 'proyecto_id' => $p->id, 'nombre' => 'Sótano 1', 'orden' => 1])
            ->assertJsonPath('1.nombre', 'Piso 1');
    });

    it('registra y edita un nivel', function () {
        [$p, , $ing] = obraConColumnas();

        $id = $this->actingAs($ing)->postJson("/api/v1/proyectos/{$p->id}/niveles", ['nombre' => ' Piso 1 ', 'orden' => 2])
            ->assertCreated()
            ->assertJsonPath('nombre', 'Piso 1')
            ->json('id');

        $this->actingAs($ing)->putJson("/api/v1/niveles/{$id}", ['nombre' => 'Piso 1', 'orden' => 3])
            ->assertOk()
            ->assertJsonPath('orden', 3);
    });

    it('valida los campos y no repite el nombre dentro del proyecto', function () {
        [$p, , $ing] = obraConColumnas();
        nivel($p);
        $otro = obra(['codigo' => 'PRY-002']);
        $otro->usuarios()->attach($ing->id);

        $this->actingAs($ing)->postJson("/api/v1/proyectos/{$p->id}/niveles", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre' => 'Este dato es requerido', 'orden' => 'Este dato es requerido']);
        $this->actingAs($ing)->postJson("/api/v1/proyectos/{$p->id}/niveles", ['nombre' => 'PISO 1', 'orden' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nombre' => 'El nivel ya existe en el proyecto',
                'orden' => 'Ingrese un valor mayor a cero',
            ]);
        // En otro proyecto el mismo nombre sí vale.
        $this->actingAs($ing)->postJson("/api/v1/proyectos/{$otro->id}/niveles", ['nombre' => 'Piso 1', 'orden' => 1])
            ->assertCreated();
    });

    it('solo Producción gestiona; Oficina técnica y Gerencia consultan', function () {
        [$p] = obraConColumnas();
        $n = nivel($p);

        foreach ([Rol::OficinaTecnica, Rol::Gerencia] as $rol) {
            $usuario = asignadoA($p, $rol);
            $this->actingAs($usuario)->getJson("/api/v1/proyectos/{$p->id}/niveles")->assertOk();
            $this->actingAs($usuario)->postJson("/api/v1/proyectos/{$p->id}/niveles", ['nombre' => 'X', 'orden' => 9])->assertForbidden();
            $this->actingAs($usuario)->putJson("/api/v1/niveles/{$n->id}", ['nombre' => 'X', 'orden' => 9])->assertForbidden();
        }
        $this->actingAs(asignadoA($p, Rol::Almacenero))->getJson("/api/v1/proyectos/{$p->id}/niveles")->assertForbidden();
    });

    it('prohíbe ver o gestionar niveles de un proyecto no asignado', function () {
        [$p] = obraConColumnas();
        $n = nivel($p);
        $ajeno = conRol(Rol::IngenieroProduccion);

        $this->actingAs($ajeno)->getJson("/api/v1/proyectos/{$p->id}/niveles")
            ->assertForbidden()
            ->assertJsonPath('message', 'No tiene acceso a este proyecto.');
        $this->actingAs($ajeno)->postJson("/api/v1/proyectos/{$p->id}/niveles", ['nombre' => 'X', 'orden' => 9])->assertForbidden();
        $this->actingAs($ajeno)->putJson("/api/v1/niveles/{$n->id}", ['nombre' => 'X', 'orden' => 9])->assertForbidden();
    });
});

describe('sectores', function () {
    it('lista los sectores de todos los niveles del proyecto', function () {
        [$p, $columnas, $ing] = obraConColumnas();
        $a = sector(nivel($p, 'Piso 1', 1), $columnas, 60);
        sector(nivel($p, 'Piso 2', 2), $columnas, 58.5, 'Sector B');
        $otra = obra(['codigo' => 'PRY-002']);
        sector(nivel($otra), $otra->partidas()->create(datosPartida()), 10);

        $this->actingAs($ing)->getJson("/api/v1/proyectos/{$p->id}/sectores")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0', [
                'id' => $a->id,
                'nivel_id' => $a->nivel_id,
                'presupuesto_id' => $columnas->id,
                'nombre' => 'Sector A',
                'volumen_presup_m3' => 60,
                'volumen_vaciado_m3' => 0,
                'fecha_culminacion' => null,
            ]);
    });

    it('registra un sector hasta exactamente el disponible de la partida, sumando todos los niveles', function () {
        [$p, $columnas, $ing] = obraConColumnas();
        sector(nivel($p, 'Piso 1', 1), $columnas, 60);
        $piso2 = nivel($p, 'Piso 2', 2);
        sector($piso2, $columnas, 58.5, 'Sector B');
        $url = "/api/v1/niveles/{$piso2->id}/sectores";
        $datos = ['nombre' => 'Columnas eje 1', 'presupuesto_id' => $columnas->id];

        // 180.50 − 60 − 58.50 = 62 disponibles.
        $this->actingAs($ing)->postJson($url, [...$datos, 'volumen_presup_m3' => 62.01])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['volumen_presup_m3' => 'El volumen excede lo presupuestado para el elemento']);

        $this->actingAs($ing)->postJson($url, [...$datos, 'volumen_presup_m3' => 62])
            ->assertCreated()
            ->assertJsonPath('volumen_presup_m3', 62)
            ->assertJsonPath('volumen_vaciado_m3', 0);
    });

    it('al editar descuenta todo menos el propio volumen del sector', function () {
        [$p, $columnas, $ing] = obraConColumnas();
        $piso = nivel($p);
        $a = sector($piso, $columnas, 60);
        sector($piso, $columnas, 58.5, 'Sector B');

        // 180.50 − 58.50 = 122 para el Sector A.
        $this->actingAs($ing)->putJson("/api/v1/sectores/{$a->id}", [
            'nombre' => 'Sector A', 'presupuesto_id' => $columnas->id, 'volumen_presup_m3' => 122,
        ])->assertOk()->assertJsonPath('volumen_presup_m3', 122);

        $this->actingAs($ing)->putJson("/api/v1/sectores/{$a->id}", [
            'nombre' => 'Sector A', 'presupuesto_id' => $columnas->id, 'volumen_presup_m3' => 122.01,
        ])->assertUnprocessable()->assertJsonValidationErrors(['volumen_presup_m3']);
    });

    it('valida campos, nombre único en el nivel y que la partida sea del mismo proyecto', function () {
        [$p, $columnas, $ing] = obraConColumnas();
        $piso = nivel($p);
        sector($piso, $columnas, 10);
        $ajena = obra(['codigo' => 'PRY-002'])->partidas()->create(datosPartida());

        $this->actingAs($ing)->postJson("/api/v1/niveles/{$piso->id}/sectores", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nombre' => 'Este dato es requerido',
                'presupuesto_id' => 'Este dato es requerido',
                'volumen_presup_m3' => 'Este dato es requerido',
            ]);

        $this->actingAs($ing)->postJson("/api/v1/niveles/{$piso->id}/sectores", [
            'nombre' => 'sector a', 'presupuesto_id' => $ajena->id, 'volumen_presup_m3' => 0,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nombre' => 'El sector ya existe en el nivel',
                'presupuesto_id' => 'La partida no pertenece al proyecto del nivel',
                'volumen_presup_m3' => 'Ingrese un valor mayor a cero',
            ]);
    });

    it('solo Producción asignada registra o edita sectores', function () {
        [$p, $columnas] = obraConColumnas();
        $piso = nivel($p);
        $s = sector($piso, $columnas, 10);
        $datos = ['nombre' => 'Nuevo', 'presupuesto_id' => $columnas->id, 'volumen_presup_m3' => 1];

        foreach ([asignadoA($p, Rol::OficinaTecnica), conRol(Rol::IngenieroProduccion), conRol(Rol::Administrador)] as $usuario) {
            $this->actingAs($usuario)->postJson("/api/v1/niveles/{$piso->id}/sectores", $datos)->assertForbidden();
            $this->actingAs($usuario)->putJson("/api/v1/sectores/{$s->id}", $datos)->assertForbidden();
        }

        expect(Sector::count())->toBe(1);
    });
});

describe('relación con el presupuesto', function () {
    it('no deja bajar el metrado de una partida por debajo de lo repartido en sectores', function () {
        [$p, $columnas] = obraConColumnas();
        $piso = nivel($p);
        sector($piso, $columnas, 60);
        sector($piso, $columnas, 58.5, 'Sector B');
        $oficina = asignadoA($p, Rol::OficinaTecnica);
        $url = "/api/v1/partidas/{$columnas->id}";
        $datos = datosPartida([
            'grupo_id' => $columnas->grupo_id,
            'descripcion' => $columnas->descripcion,
            'elemento' => 'Columna',
            'resistencia_id' => resistencia(280)->id,
        ]);

        $this->actingAs($oficina)->putJson($url, [...$datos, 'metrado_m3' => 118.49])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'metrado_m3' => 'El metrado es menor al volumen ya repartido en sectores (118.50 m³)',
            ]);
        expect((float) $columnas->fresh()->metrado_m3)->toBe(180.5);

        $this->actingAs($oficina)->putJson($url, [...$datos, 'metrado_m3' => 118.5])
            ->assertOk()
            ->assertJsonPath('metrado_m3', 118.5);
    });
});

it('el seeder de demo arma los niveles y sectores de las pantallas', function () {
    $this->seed([GrupoPartidaSeeder::class, ResistenciaSeeder::class, UsuarioDemoSeeder::class, ProyectoDemoSeeder::class, PartidaDemoSeeder::class, NivelDemoSeeder::class]);
    $this->seed(NivelDemoSeeder::class);

    $p1 = Proyecto::where('codigo', 'PRY-001')->first();
    expect($p1->niveles()->orderBy('orden')->pluck('nombre')->all())->toBe(['Sótano 1', 'Piso 1', 'Piso 2']);
    expect(Sector::count())->toBe(5);
    expect((float) Sector::where('nombre', 'Sector C')->value('volumen_presup_m3'))->toBe(122.5);
});

describe('culminación de sectores', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'America/Lima'));
    });

    it('Producción culmina un sector con su fecha y lo reabre', function () {
        [$p, $columnas, $ing] = obraConColumnas();
        $s = sector(nivel($p), $columnas, 60);

        $this->actingAs($ing)->postJson("/api/v1/sectores/{$s->id}/culminar", ['fecha_culminacion' => '2026-11-09'])
            ->assertOk()
            ->assertJsonPath('data.fecha_culminacion', '2026-11-09')
            ->assertJsonPath('message', 'Sector culminado correctamente');
        expect($s->fresh()->fecha_culminacion->toDateString())->toBe('2026-11-09');
        expect($s->fresh()->culminado_por)->toBe($ing->id);

        $this->actingAs($ing)->postJson("/api/v1/sectores/{$s->id}/culminar", ['fecha_culminacion' => '2026-11-09'])
            ->assertUnprocessable()->assertJsonValidationErrors(['sector' => 'El sector ya está culminado']);
        // Culminado no se edita.
        $this->actingAs($ing)->putJson("/api/v1/sectores/{$s->id}", ['nombre' => 'Sector A', 'presupuesto_id' => $columnas->id, 'volumen_presup_m3' => 50])
            ->assertUnprocessable()->assertJsonValidationErrors(['sector' => 'El sector está culminado; reábralo para editarlo']);

        $this->actingAs($ing)->postJson("/api/v1/sectores/{$s->id}/reabrir")
            ->assertOk()
            ->assertJsonPath('data.fecha_culminacion', null)
            ->assertJsonPath('message', 'Sector reabierto correctamente');
        expect($s->fresh()->culminado_por)->toBeNull();
        $this->actingAs($ing)->postJson("/api/v1/sectores/{$s->id}/reabrir")
            ->assertUnprocessable()->assertJsonValidationErrors(['sector' => 'El sector no está culminado']);
    });

    it('valida la fecha: requerida, no futura, no antes del inicio del proyecto ni de la última guía del sector', function () {
        [$p, $columnas, $ing] = obraConColumnas();
        $p->update(['fecha_inicio' => '2026-10-01']);
        $s = sector(nivel($p), $columnas, 60);
        $guia = GuiaRemision::create([
            'proyecto_id' => $p->id, 'proveedor_id' => Proveedor::factory()->create()->id,
            'numero' => 'T001-00000001', 'fecha' => '2026-10-20', 'resistencia_id' => resistencia(280)->id,
            'volumen_m3' => 8, 'foto_ruta' => 'guias/1/f.jpg', 'estado_asignacion' => EstadoAsignacion::Asignada,
            'origen' => OrigenGuia::Manual, 'registrado_por' => $ing->id,
        ]);
        Asignacion::create(['guia_id' => $guia->id, 'sector_id' => $s->id, 'volumen_m3' => 8, 'registrado_por' => $ing->id]);
        $url = "/api/v1/sectores/{$s->id}/culminar";
        $antes = 'La fecha de culminación no puede ser anterior al inicio del proyecto ni a la última guía del sector';

        foreach ([
            [null, 'Ingrese la fecha de culminación'],
            ['09/11/2026', 'Ingrese una fecha válida'],
            ['2026-11-10', 'La fecha de culminación no puede ser posterior a hoy'],
            ['2026-09-30', $antes],
            ['2026-10-19', $antes],
        ] as [$fecha, $mensaje]) {
            $this->actingAs($ing)->postJson($url, ['fecha_culminacion' => $fecha])
                ->assertUnprocessable()->assertJsonValidationErrors(['fecha_culminacion' => $mensaje]);
        }
        expect($s->fresh()->fecha_culminacion)->toBeNull();

        $this->actingAs($ing)->postJson($url, ['fecha_culminacion' => '2026-10-20'])->assertOk();
    });

    it('solo Producción asignada culmina o reabre; a un proyecto ajeno responde 403 sin validar', function () {
        [$p, $columnas] = obraConColumnas();
        $s = sector(nivel($p), $columnas, 60);

        foreach ([Rol::OficinaTecnica, Rol::Gerencia, Rol::Almacenero] as $rol) {
            $this->actingAs(asignadoA($p, $rol))->postJson("/api/v1/sectores/{$s->id}/culminar", ['fecha_culminacion' => '2026-11-09'])->assertForbidden();
            $this->actingAs(asignadoA($p, $rol))->postJson("/api/v1/sectores/{$s->id}/reabrir")->assertForbidden();
        }
        $ajeno = conRol(Rol::IngenieroProduccion);
        $this->actingAs($ajeno)->postJson("/api/v1/sectores/{$s->id}/culminar", [])
            ->assertForbidden()->assertJsonPath('message', 'No tiene acceso a este proyecto.');
        $this->actingAs($ajeno)->postJson("/api/v1/sectores/{$s->id}/reabrir")->assertForbidden();
        expect($s->fresh()->fecha_culminacion)->toBeNull();
    });
});
