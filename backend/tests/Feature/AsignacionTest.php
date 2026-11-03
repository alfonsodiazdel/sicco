<?php

use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use App\Enums\Rol;
use App\Models\GuiaRemision;
use App\Models\HistorialAsignacion;
use App\Models\Partida;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\Sector;
use App\Models\User;

/** Proyecto con una partida de losas (factor 5 %), dos sectores de 60 m³ y un ingeniero asignado. */
function obraConSectores(): array
{
    $proyecto = obra();
    $partida = $proyecto->partidas()->create(datosPartida([
        'descripcion' => "CONCRETO F'C=210 kg/cm2 - LOSAS",
        'elemento' => 'Losa',
        'metrado_m3' => 200,
        'factor_desperdicio' => 5,
    ]));
    $nivel = $proyecto->niveles()->create(['nombre' => 'Piso 1', 'orden' => 1]);

    return [
        $proyecto,
        sectorDeVaciado($partida, $nivel->id, 'Sector A'),
        sectorDeVaciado($partida, $nivel->id, 'Sector B'),
        asignadoA($proyecto, Rol::IngenieroProduccion),
    ];
}

function sectorDeVaciado(Partida $partida, int $nivelId, string $nombre, float $volumen = 60): Sector
{
    return Sector::create([
        'nivel_id' => $nivelId,
        'presupuesto_id' => $partida->id,
        'nombre' => $nombre,
        'volumen_presup_m3' => $volumen,
    ]);
}

function guiaDe(Proyecto $proyecto, array $atributos = []): GuiaRemision
{
    static $numero = 0;
    $numero++;

    return GuiaRemision::create([
        'proyecto_id' => $proyecto->id,
        'proveedor_id' => Proveedor::factory()->create()->id,
        'numero' => sprintf('T001-%08d', $numero),
        'fecha' => today()->toDateString(),
        'resistencia_id' => resistencia(210)->id,
        'volumen_m3' => 8.5,
        'foto_ruta' => "guias/{$proyecto->id}/foto.jpg",
        'estado_asignacion' => EstadoAsignacion::SinSector,
        'origen' => OrigenGuia::Manual,
        'registrado_por' => User::factory()->rol(Rol::Almacenero)->create()->id,
        ...$atributos,
    ]);
}

function repartirGuia(GuiaRemision $guia, array $reparto)
{
    return test()->putJson("/api/v1/guias/{$guia->id}/asignaciones", [
        'asignaciones' => array_map(fn ($sector, $volumen) => ['sector_id' => $sector, 'volumen_m3' => $volumen], array_keys($reparto), $reparto),
    ]);
}

describe('guías sin sector (CU10)', function () {
    it('lista las guías con volumen por asignar, de la más antigua a la más reciente', function () {
        [$p, $a, , $ingeniero] = obraConSectores();
        $hoy = guiaDe($p);
        $antigua = guiaDe($p, ['fecha' => today()->subDays(5)->toDateString()]);
        $parcial = guiaDe($p, ['fecha' => today()->subDay()->toDateString(), 'estado_asignacion' => EstadoAsignacion::Parcial]);
        $parcial->asignaciones()->create(['sector_id' => $a->id, 'volumen_m3' => 3, 'registrado_por' => $ingeniero->id]);
        guiaDe($p, ['estado_asignacion' => EstadoAsignacion::Asignada]);
        guiaDe(obra(['codigo' => 'PRY-002']));

        $this->actingAs($ingeniero)
            ->getJson("/api/v1/proyectos/{$p->id}/guias/sin-sector")
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('*.id', [$antigua->id, $parcial->id, $hoy->id])
            ->assertJsonPath('0.dias_transcurridos', 5)
            ->assertJsonPath('0.atrasada', true)
            ->assertJsonPath('0.volumen_pendiente_m3', 8.5)
            ->assertJsonPath('1.volumen_asignado_m3', 3)
            ->assertJsonPath('1.volumen_pendiente_m3', 5.5)
            ->assertJsonPath('2.dias_transcurridos', 0)
            ->assertJsonPath('2.atrasada', false);
    });

    it('resalta solo las de más de 2 días', function () {
        [$p, , , $ingeniero] = obraConSectores();
        guiaDe($p, ['fecha' => today()->subDays(2)->toDateString()]);
        guiaDe($p, ['fecha' => today()->subDays(3)->toDateString()]);

        $this->actingAs($ingeniero)
            ->getJson("/api/v1/proyectos/{$p->id}/guias/sin-sector")
            ->assertJsonPath('*.atrasada', [true, false]);
    });

    it('filtra por rango de fechas', function () {
        [$p, , , $ingeniero] = obraConSectores();
        guiaDe($p, ['fecha' => '2026-10-01']);
        $dentro = guiaDe($p, ['fecha' => '2026-10-15']);
        guiaDe($p, ['fecha' => '2026-10-31']);

        $this->actingAs($ingeniero)
            ->getJson("/api/v1/proyectos/{$p->id}/guias/sin-sector?desde=2026-10-10&hasta=2026-10-20")
            ->assertOk()
            ->assertJsonPath('*.id', [$dentro->id]);
    });

    it('rechaza un rango invertido', function () {
        [$p, , , $ingeniero] = obraConSectores();

        $this->actingAs($ingeniero)
            ->getJson("/api/v1/proyectos/{$p->id}/guias/sin-sector?desde=2026-10-20&hasta=2026-10-10")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hasta' => 'La fecha final debe ser igual o posterior a la inicial']);
    });

    it('devuelve una lista vacía si no hay pendientes', function () {
        [$p, , , $ingeniero] = obraConSectores();

        $this->actingAs($ingeniero)
            ->getJson("/api/v1/proyectos/{$p->id}/guias/sin-sector")
            ->assertOk()
            ->assertJsonCount(0);
    });

    it('la consultan Producción, Oficina técnica y el administrador', function (Rol $rol) {
        [$p] = obraConSectores();

        $this->actingAs(asignadoA($p, $rol))
            ->getJson("/api/v1/proyectos/{$p->id}/guias/sin-sector")
            ->assertOk();
    })->with([Rol::IngenieroProduccion, Rol::OficinaTecnica, Rol::Administrador]);

    it('no la consultan los demás roles', function (Rol $rol) {
        [$p] = obraConSectores();

        $this->actingAs(asignadoA($p, $rol))
            ->getJson("/api/v1/proyectos/{$p->id}/guias/sin-sector")
            ->assertForbidden();
    })->with([Rol::Almacenero, Rol::Gerencia]);

    it('no muestra las de un proyecto no asignado', function () {
        [, , , $ingeniero] = obraConSectores();
        $ajeno = obra(['codigo' => 'PRY-002']);

        $this->actingAs($ingeniero)
            ->getJson("/api/v1/proyectos/{$ajeno->id}/guias/sin-sector")
            ->assertForbidden();
    });

    it('exporta la lista a Excel', function () {
        [$p, , , $ingeniero] = obraConSectores();
        guiaDe($p, ['fecha' => today()->subDays(4)->toDateString()]);

        $respuesta = $this->actingAs($ingeniero)
            ->get("/api/v1/proyectos/{$p->id}/guias/sin-sector/exportar", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('guias-sin-sector-PRY-001-'.today()->format('Ymd').'.xlsx');

        // Un .xlsx es un ZIP: empieza con «PK».
        expect(substr($respuesta->streamedContent(), 0, 2))->toBe('PK');
    });
});

describe('asignación a sectores (CU09)', function () {
    it('muestra la guía con su volumen disponible y su reparto', function () {
        [$p, $a, , $ingeniero] = obraConSectores();
        $guia = guiaDe($p);

        $this->actingAs($ingeniero)
            ->getJson("/api/v1/guias/{$guia->id}/asignaciones")
            ->assertOk()
            ->assertJsonPath('guia.id', $guia->id)
            ->assertJsonPath('guia.volumen_pendiente_m3', 8.5)
            ->assertJsonPath('asignaciones', []);
    });

    it('reparte la guía en dos sectores y la deja asignada', function () {
        [$p, $a, $b, $ingeniero] = obraConSectores();
        $guia = guiaDe($p);

        $this->actingAs($ingeniero);
        repartirGuia($guia, [$a->id => 5, $b->id => 3.5])
            ->assertOk()
            ->assertJsonPath('message', 'Guía asignada correctamente')
            ->assertJsonPath('guia.estado_asignacion', 'asignada')
            ->assertJsonPath('guia.volumen_pendiente_m3', 0)
            ->assertJsonCount(2, 'asignaciones');

        expect($a->refresh()->volumen_vaciado_m3)->toBe('5.00')
            ->and($b->refresh()->volumen_vaciado_m3)->toBe('3.50')
            ->and($guia->refresh()->estado_asignacion)->toBe(EstadoAsignacion::Asignada);
    });

    it('deja la guía en parcial si falta volumen', function () {
        [$p, $a, , $ingeniero] = obraConSectores();
        $guia = guiaDe($p);

        $this->actingAs($ingeniero);
        repartirGuia($guia, [$a->id => 4])->assertOk()->assertJsonPath('guia.estado_asignacion', 'parcial');

        $this->getJson("/api/v1/proyectos/{$p->id}/guias/sin-sector")
            ->assertJsonPath('0.volumen_pendiente_m3', 4.5);
    });

    it('rechaza una suma mayor al volumen de la guía', function () {
        [$p, $a, $b, $ingeniero] = obraConSectores();
        $guia = guiaDe($p);

        $this->actingAs($ingeniero);
        repartirGuia($guia, [$a->id => 5, $b->id => 3.51])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['asignaciones' => 'El volumen asignado supera el de la guía']);

        expect($a->refresh()->volumen_vaciado_m3)->toBe('0.00')
            ->and($guia->asignaciones()->count())->toBe(0);
    });

    it('al corregir el reparto ajusta el vaciado de los sectores y guarda el historial', function () {
        [$p, $a, $b, $ingeniero] = obraConSectores();
        $guia = guiaDe($p);

        $this->actingAs($ingeniero);
        repartirGuia($guia, [$a->id => 5, $b->id => 3.5])->assertOk();
        repartirGuia($guia, [$b->id => 8.5])->assertOk();

        expect($a->refresh()->volumen_vaciado_m3)->toBe('0.00')
            ->and($b->refresh()->volumen_vaciado_m3)->toBe('8.50')
            ->and($guia->asignaciones()->pluck('sector_id')->all())->toBe([$b->id]);

        $historial = HistorialAsignacion::where('guia_id', $guia->id)->orderBy('id')->get();
        expect($historial)->toHaveCount(2)
            ->and($historial[1]->usuario_id)->toBe($ingeniero->id)
            ->and($historial[1]->antes)->toBe([
                ['sector_id' => $a->id, 'volumen_m3' => 5],
                ['sector_id' => $b->id, 'volumen_m3' => 3.5],
            ])
            ->and($historial[1]->despues)->toBe([['sector_id' => $b->id, 'volumen_m3' => 8.5]]);
    });

    it('suma al vaciado del sector lo de varias guías', function () {
        [$p, $a, , $ingeniero] = obraConSectores();

        $this->actingAs($ingeniero);
        repartirGuia(guiaDe($p), [$a->id => 8.5])->assertOk();
        repartirGuia(guiaDe($p), [$a->id => 7.25])->assertOk();

        expect($a->refresh()->volumen_vaciado_m3)->toBe('15.75');
    });

    it('avisa los sectores que pasan lo presupuestado más el desperdicio', function () {
        [$p, , , $ingeniero] = obraConSectores();
        // Límite: 10 × 1.05 = 10.50 m³.
        $chico = sectorDeVaciado(Partida::first(), Sector::first()->nivel_id, 'Sector C', 10);

        $this->actingAs($ingeniero);
        repartirGuia(guiaDe($p, ['volumen_m3' => 10.5]), [$chico->id => 10.5])
            ->assertOk()
            ->assertJsonPath('sectores_excedidos', []);
        repartirGuia(guiaDe($p, ['volumen_m3' => 1]), [$chico->id => 0.01])
            ->assertOk()
            ->assertJsonPath('sectores_excedidos', [['id' => $chico->id, 'nombre' => 'Sector C']]);
    });

    it('valida cada fila del reparto', function (array $fila, string $campo, string $mensaje) {
        [$p, $a, , $ingeniero] = obraConSectores();

        $this->actingAs($ingeniero)
            ->putJson('/api/v1/guias/'.guiaDe($p)->id.'/asignaciones', ['asignaciones' => [['sector_id' => $a->id, ...$fila]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["asignaciones.0.{$campo}" => $mensaje]);
    })->with([
        'sin volumen' => [['volumen_m3' => null], 'volumen_m3', 'Este dato es requerido'],
        'cero' => [['volumen_m3' => 0], 'volumen_m3', 'Ingrese un valor mayor a cero'],
        'tres decimales' => [['volumen_m3' => 1.005], 'volumen_m3', 'Ingrese como máximo dos decimales'],
        'sin sector' => [['sector_id' => null, 'volumen_m3' => 1], 'sector_id', 'Este dato es requerido'],
    ]);

    it('exige al menos un sector y sin repetir', function () {
        [$p, $a, , $ingeniero] = obraConSectores();
        $guia = guiaDe($p);

        $this->actingAs($ingeniero)
            ->putJson("/api/v1/guias/{$guia->id}/asignaciones", ['asignaciones' => []])
            ->assertJsonValidationErrors(['asignaciones' => 'Este dato es requerido']);
        $this->putJson("/api/v1/guias/{$guia->id}/asignaciones", ['asignaciones' => [
            ['sector_id' => $a->id, 'volumen_m3' => 1],
            ['sector_id' => $a->id, 'volumen_m3' => 2],
        ]])->assertJsonValidationErrors(['asignaciones.1.sector_id' => 'El sector ya está en la asignación']);
    });

    it('no acepta sectores de otro proyecto', function () {
        [$p, , , $ingeniero] = obraConSectores();
        $ajeno = obra(['codigo' => 'PRY-002']);
        $partida = $ajeno->partidas()->create(datosPartida());
        $otro = sectorDeVaciado($partida, $ajeno->niveles()->create(['nombre' => 'Piso 1', 'orden' => 1])->id, 'Sector X');

        $this->actingAs($ingeniero);
        repartirGuia(guiaDe($p), [$otro->id => 1])
            ->assertJsonValidationErrors(['asignaciones.0.sector_id' => 'El sector no pertenece al proyecto de la guía']);
    });

    it('solo asigna el ingeniero de producción', function (Rol $rol) {
        [$p, $a] = obraConSectores();

        $this->actingAs(asignadoA($p, $rol));
        repartirGuia(guiaDe($p), [$a->id => 1])->assertForbidden();
    })->with([Rol::Administrador, Rol::Almacenero, Rol::OficinaTecnica, Rol::Gerencia]);

    it('no asigna guías de un proyecto no asignado', function () {
        [, , , $ingeniero] = obraConSectores();
        $ajeno = obra(['codigo' => 'PRY-002']);

        $this->actingAs($ingeniero);
        repartirGuia(guiaDe($ajeno), [1 => 1])->assertForbidden();
        $this->getJson('/api/v1/guias/'.guiaDe($ajeno)->id.'/asignaciones')->assertForbidden();
    });
});
