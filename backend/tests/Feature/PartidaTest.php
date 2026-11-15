<?php

use App\Enums\Rol;
use App\Models\Concreto;
use App\Models\Partida;
use App\Models\User;

const CAMPOS_PARTIDA = [
    'id', 'proyecto_id', 'grupo_id', 'item', 'descripcion', 'elemento', 'concreto_id', 'resistencia_id',
    'resistencia_fc',
    'metrado_m3', 'factor_desperdicio', 'precio_unitario',
];

describe('listar', function () {
    it('devuelve solo las partidas del proyecto, con números y no texto', function () {
        $p = obra();
        $otro = obra(['codigo' => 'PRY-002']);
        $zapata = partida($p, datosPartida(['metrado_m3' => 180.5]));
        partida($otro, datosPartida());

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
        $partida = partida($p, datosPartida());
        $otro = obra(['codigo' => 'PRY-002']);

        $this->actingAs(asignadoA($p))
            ->putJson("/api/v1/partidas/{$partida->id}", datosPartida(['metrado_m3' => 300, 'proyecto_id' => $otro->id]))
            ->assertOk()
            ->assertJsonPath('metrado_m3', 300)
            ->assertJsonPath('proyecto_id', $p->id);
    });

    it('prohíbe editar la partida de un proyecto no asignado', function () {
        $partida = partida(obra(), datosPartida());

        $this->actingAs(conRol(Rol::OficinaTecnica))->putJson("/api/v1/partidas/{$partida->id}", datosPartida(['metrado_m3' => 1]))
            ->assertForbidden();

        expect((float) $partida->fresh()->metrado_m3)->toBe(320.0);
    });
});

describe('precio del concreto', function () {
    it('las partidas con la misma resistencia en el proyecto comparten un concreto y su precio', function () {
        $p = obra();
        $u = asignadoA($p);

        $primera = $this->actingAs($u)->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida(['precio_unitario' => 250]))
            ->assertCreated();
        $segunda = $this->actingAs($u)->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida(['elemento' => 'Viga', 'precio_unitario' => 250]))
            ->assertCreated()
            ->assertJsonPath('precio_unitario', 250);

        expect($segunda->json('concreto_id'))->toBe($primera->json('concreto_id'));
        expect(Concreto::count())->toBe(1);
    });

    it('pide confirmar un precio nuevo si otras partidas usan el concreto, y sin confirmar no guarda nada', function () {
        $p = obra();
        partida($p, datosPartida(['precio_unitario' => 250]));
        partida($p, datosPartida(['elemento' => 'Viga', 'precio_unitario' => 250]));

        $this->actingAs(asignadoA($p))->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida(['elemento' => 'Placa', 'precio_unitario' => 260]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'requiere_confirmacion_precio')
            ->assertJsonPath('message', "El precio de f'c 210 kg/cm² (S/ 250.00) lo usan otras 2 partidas del proyecto; al guardar se actualizará en todas.")
            ->assertJsonValidationErrors(['precio_unitario']);

        expect(Partida::count())->toBe(2);
        expect((float) Concreto::sole()->precio_unitario)->toBe(250.0);
    });

    it('con la confirmación cambia el precio en todas las partidas del concreto', function () {
        $p = obra();
        $zapata = partida($p, datosPartida(['precio_unitario' => 250]));
        $viga = partida($p, datosPartida(['elemento' => 'Viga', 'precio_unitario' => 250]));
        $u = asignadoA($p);

        $this->actingAs($u)->putJson("/api/v1/partidas/{$viga->id}", datosPartida(['elemento' => 'Viga', 'precio_unitario' => 270]))
            ->assertUnprocessable()
            ->assertJsonPath('message', "El precio de f'c 210 kg/cm² (S/ 250.00) lo usa otra partida del proyecto; al guardar se actualizará también en ella.");

        $this->actingAs($u)->putJson("/api/v1/partidas/{$viga->id}", datosPartida(['elemento' => 'Viga', 'precio_unitario' => 270, 'confirmado_precio' => true]))
            ->assertOk()
            ->assertJsonPath('precio_unitario', 270);

        $this->actingAs($u)->getJson("/api/v1/proyectos/{$p->id}/partidas")
            ->assertJsonPath('0.id', $zapata->id)
            ->assertJsonPath('0.precio_unitario', 270)
            ->assertJsonPath('1.precio_unitario', 270);
    });

    it('cambia sin confirmar el precio de un concreto que solo usa la partida editada', function () {
        $p = obra();
        $partida = partida($p, datosPartida(['precio_unitario' => 250]));

        $this->actingAs(asignadoA($p))->putJson("/api/v1/partidas/{$partida->id}", datosPartida(['precio_unitario' => 275]))
            ->assertOk()
            ->assertJsonPath('precio_unitario', 275);
    });

    it('al cambiar la resistencia la partida pasa al concreto de esa resistencia, con su precio', function () {
        $p = obra();
        $partida = partida($p, datosPartida(['precio_unitario' => 250]));
        $r280 = resistencia(280);

        $this->actingAs(asignadoA($p))->putJson("/api/v1/partidas/{$partida->id}", datosPartida(['resistencia_id' => $r280->id, 'precio_unitario' => 300]))
            ->assertOk()
            ->assertJsonPath('resistencia_id', $r280->id)
            ->assertJsonPath('resistencia_fc', "f'c 280 kg/cm²")
            ->assertJsonPath('precio_unitario', 300);

        expect($partida->fresh()->concreto->resistencia_id)->toBe($r280->id);
    });

    it('cada proyecto tiene su propio precio para la misma resistencia', function () {
        $p = obra();
        $otro = obra(['codigo' => 'PRY-002']);
        partida($otro, datosPartida(['precio_unitario' => 250]));

        $this->actingAs(asignadoA($p))->postJson("/api/v1/proyectos/{$p->id}/partidas", datosPartida(['precio_unitario' => 400]))
            ->assertCreated()
            ->assertJsonPath('precio_unitario', 400);

        expect((float) $otro->concretos()->sole()->precio_unitario)->toBe(250.0);
    });
});
