<?php

use App\Enums\Rol;
use App\Models\Partida;
use App\Models\User;

const CAMPOS_PARTIDA = [
    'id', 'proyecto_id', 'grupo_id', 'item', 'descripcion', 'elemento', 'resistencia_id',
    'resistencia_fc',
    'metrado_m3', 'factor_desperdicio', 'precio_unitario',
];

describe('listar', function () {
    it('devuelve solo las partidas del proyecto, con números y no texto', function () {
        $p = obra();
        $otro = obra(['codigo' => 'PRY-002']);
        $zapata = $p->partidas()->create(datosPartida(['metrado_m3' => 180.5]));
        $otro->partidas()->create(datosPartida());

        $respuesta = $this->actingAs(asignadoA($p))->getJson("/api/v1/proyectos/{$p->id}/partidas")->assertOk();

        expect($respuesta->json())->toHaveCount(1);
        expect(array_keys($respuesta->json('0')))->toEqualCanonicalizing(CAMPOS_PARTIDA);
        $respuesta->assertJsonPath('0.id', $zapata->id)
            ->assertJsonPath('0.resistencia_id', resistencia(210)->id)
            ->assertJsonPath('0.resistencia_fc', "f'c 210 kg/cm²")
            ->assertJsonPath('0.metrado_m3', 180.5)
            ->assertJsonPath('0.factor_desperdicio', 5)
            ->assertJsonPath('0.precio_unitario', 385);
    });

    it('la consultan Producción y Gerencia si están asignados, y el administrador siempre', function () {
        $p = obra();

        foreach ([Rol::IngenieroProduccion, Rol::Gerencia] as $rol) {
            $this->actingAs(asignadoA($p, $rol))->getJson("/api/v1/proyectos/{$p->id}/partidas")->assertOk();
        }
        $this->actingAs(User::factory()->rol(Rol::Administrador)->create())
            ->getJson("/api/v1/proyectos/{$p->id}/partidas")
            ->assertOk();
    });

    it('prohíbe ver el presupuesto de un proyecto no asignado', function () {
        $p = obra();

        $this->actingAs(conRol(Rol::OficinaTecnica))->getJson("/api/v1/proyectos/{$p->id}/partidas")
            ->assertForbidden()
            ->assertJsonPath('code', 'sin_permiso');
    });

    it('prohíbe al almacenero aunque esté asignado', function () {
        $p = obra();

        $this->actingAs(asignadoA($p, Rol::Almacenero))->getJson("/api/v1/proyectos/{$p->id}/partidas")->assertForbidden();
    });
});

describe('registrar', function () {
    it('registra la partida en el proyecto y la devuelve', function () {
        $p = obra();

        $respuesta = $this->actingAs(asignadoA($p))->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida())
            ->assertCreated()
            ->assertJsonPath('proyecto_id', $p->id)
            ->assertJsonPath('descripcion', "CONCRETO F'C=210 kg/cm2 - ZAPATAS");

        expect(array_keys($respuesta->json()))->toEqualCanonicalizing(CAMPOS_PARTIDA);
        $this->assertDatabaseHas('partidas', ['id' => $respuesta->json('id'), 'proyecto_id' => $p->id, 'metrado_m3' => 320]);
    });

    it('guarda el ítem vacío como null', function () {
        $p = obra();

        $this->actingAs(asignadoA($p))->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida(['item' => '  ']))
            ->assertCreated()
            ->assertJsonPath('item', null);
    });

    it('exige los campos obligatorios con el mensaje de la tesis', function () {
        $p = obra();

        $this->actingAs(asignadoA($p))->postJson("/api/v1/proyectos/{$p->id}/partidas", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'grupo_id' => 'Este dato es requerido',
                'descripcion' => 'Este dato es requerido',
                'elemento' => 'Este dato es requerido',
                'resistencia_id' => 'Este dato es requerido',
                'metrado_m3' => 'Este dato es requerido',
                'factor_desperdicio' => 'Este dato es requerido',
                'precio_unitario' => 'Este dato es requerido',
            ])
            ->assertJsonMissingValidationErrors(['item']);

        expect(Partida::count())->toBe(0);
    });

    it('valida importes, listas, grupo e ítem', function () {
        $p = obra();

        $this->actingAs(asignadoA($p))->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida([
            'grupo_id' => 999999,
            'item' => '03-01',
            'elemento' => 'Muro',
            'resistencia_id' => 999999,
            'metrado_m3' => 0,
            'factor_desperdicio' => -1,
            'precio_unitario' => 12.345,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'grupo_id' => 'El grupo no existe',
                'item' => 'Use solo números separados por puntos',
                'elemento' => 'Este dato es requerido',
                'resistencia_id' => 'La resistencia no existe',
                'metrado_m3' => 'Ingrese un valor mayor a cero',
                'factor_desperdicio' => 'Ingrese un valor mayor o igual a cero',
                'precio_unitario' => 'Ingrese como máximo dos decimales',
            ]);
    });

    it('pide confirmar un desperdicio mayor a 15 % y lo registra al confirmarlo', function () {
        $p = obra();
        $usuario = asignadoA($p);
        $url = "/api/v1/proyectos/{$p->id}/partidas";

        $this->actingAs($usuario)->postJson($url, datosPartida(['factor_desperdicio' => 15.5]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'requiere_confirmacion')
            ->assertJsonPath('message', 'El factor de desperdicio es 15.50 %, mayor a 15 %. ¿Confirma el valor?');
        expect(Partida::count())->toBe(0);

        $this->actingAs($usuario)->postJson($url, datosPartida(['factor_desperdicio' => 15]))->assertCreated();
        $this->actingAs($usuario)
            ->postJson($url, datosPartida(['factor_desperdicio' => 15.5, 'confirmado' => true]))
            ->assertCreated()
            ->assertJsonPath('factor_desperdicio', 15.5);
    });

    it('prohíbe registrar en un proyecto no asignado o sin ser Oficina técnica', function () {
        $p = obra();

        $this->actingAs(conRol(Rol::OficinaTecnica))->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida())->assertForbidden();
        $this->actingAs(asignadoA($p, Rol::IngenieroProduccion))
            ->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida())
            ->assertForbidden();
        $this->actingAs(User::factory()->rol(Rol::Administrador)->create())
            ->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida())
            ->assertForbidden();

        expect(Partida::count())->toBe(0);
    });
});

describe('editar', function () {
    it('actualiza la partida sin cambiarla de proyecto', function () {
        $p = obra();
        $partida = $p->partidas()->create(datosPartida());
        $otro = obra(['codigo' => 'PRY-002']);

        $this->actingAs(asignadoA($p))
            ->putJson("/api/v1/partidas/{$partida->id}", datosPartida(['metrado_m3' => 300, 'proyecto_id' => $otro->id]))
            ->assertOk()
            ->assertJsonPath('metrado_m3', 300)
            ->assertJsonPath('proyecto_id', $p->id);
    });

    it('prohíbe editar la partida de un proyecto no asignado', function () {
        $partida = obra()->partidas()->create(datosPartida());

        $this->actingAs(conRol(Rol::OficinaTecnica))->putJson("/api/v1/partidas/{$partida->id}", datosPartida(['metrado_m3' => 1]))
            ->assertForbidden();

        expect((float) $partida->fresh()->metrado_m3)->toBe(320.0);
    });
});
