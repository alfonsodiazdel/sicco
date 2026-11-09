<?php

use App\Enums\Rol;
use App\Models\Proyecto;
use App\Models\User;
use Database\Seeders\ProyectoDemoSeeder;
use Database\Seeders\RolSeeder;
use Database\Seeders\UsuarioDemoSeeder;
use Illuminate\Support\Facades\DB;

function oficina(): User
{
    return User::factory()->rol(Rol::OficinaTecnica)->create();
}

function ingeniero(array $atributos = []): User
{
    return User::factory()->rol(Rol::IngenieroProduccion)->create($atributos);
}

function datosProyecto(array $cambios = []): array
{
    return [
        'codigo' => 'PRY-010',
        'nombre' => 'Edificio Miraflores',
        'direccion' => 'Av. Larco 345, Miraflores',
        'fecha_inicio' => '2026-11-02',
        'fecha_fin' => '2027-06-30',
        'ingeniero_id' => ingeniero()->id,
        ...$cambios,
    ];
}

function proyecto(array $atributos = []): Proyecto
{
    return Proyecto::create([
        'codigo' => 'PRY-001',
        'nombre' => 'Residencial Los Álamos',
        'direccion' => 'Av. Primavera 1250, Santiago de Surco',
        'fecha_inicio' => '2026-03-02',
        'fecha_fin' => '2027-02-26',
        'ingeniero_id' => $atributos['ingeniero_id'] ?? ingeniero()->id,
        'estado' => 'Activo',
        ...$atributos,
    ]);
}

const CAMPOS_FILA = ['id', 'codigo', 'nombre', 'direccion', 'fecha_inicio', 'fecha_fin', 'ingeniero_id', 'estado'];

describe('listar', function () {
    it('devuelve los proyectos ordenados por código, solo con los campos de la fila', function () {
        $ing = ingeniero();
        proyecto(['codigo' => 'PRY-002', 'ingeniero_id' => $ing->id]);
        $p1 = proyecto(['codigo' => 'PRY-001', 'ingeniero_id' => $ing->id]);

        $respuesta = $this->actingAs(oficina())->getJson('/api/v1/proyectos')->assertOk();

        expect($respuesta->json('*.codigo'))->toBe(['PRY-001', 'PRY-002']);
        expect(array_keys($respuesta->json('0')))->toEqualCanonicalizing(CAMPOS_FILA);
        $respuesta->assertJsonPath('0', [
            'id' => $p1->id,
            'codigo' => 'PRY-001',
            'nombre' => 'Residencial Los Álamos',
            'direccion' => 'Av. Primavera 1250, Santiago de Surco',
            'fecha_inicio' => '2026-03-02',
            'fecha_fin' => '2027-02-26',
            'ingeniero_id' => $ing->id,
            'estado' => 'Activo',
        ]);
    });

    it('deja al administrador consultar la lista, como en el resto de módulos', function () {
        proyecto();

        $this->actingAs(User::factory()->rol(Rol::Administrador)->create())
            ->getJson('/api/v1/proyectos')
            ->assertOk()
            ->assertJsonPath('0.codigo', 'PRY-001');
    });

    it('prohíbe listar a los demás perfiles', function (Rol $rol) {
        $this->actingAs(User::factory()->rol($rol)->create())
            ->getJson('/api/v1/proyectos')
            ->assertForbidden()
            ->assertJsonPath('code', 'sin_permiso');
    })->with([Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia]);
});

describe('crear', function () {
    it('registra el proyecto, nace Activo y lo devuelve', function () {
        $datos = datosProyecto();

        $respuesta = $this->actingAs(oficina())->postJson('/api/v1/proyectos', $datos)
            ->assertCreated()
            ->assertJsonPath('codigo', 'PRY-010')
            ->assertJsonPath('estado', 'Activo')
            ->assertJsonPath('fecha_fin', '2027-06-30');

        expect(array_keys($respuesta->json()))->toEqualCanonicalizing(CAMPOS_FILA);
        $this->assertDatabaseHas('proyectos', [...$datos, 'id' => $respuesta->json('id'), 'estado' => 'Activo']);
    });

    it('ignora el estado al registrar: un proyecto nuevo siempre nace Activo', function () {
        $this->actingAs(oficina())->postJson('/api/v1/proyectos', datosProyecto(['estado' => 'Finalizado']))
            ->assertCreated()
            ->assertJsonPath('estado', 'Activo');
    });

    it('exige los seis campos con el mensaje de la tesis', function () {
        $this->actingAs(oficina())->postJson('/api/v1/proyectos', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'codigo' => 'Este dato es requerido',
                'nombre' => 'Este dato es requerido',
                'direccion' => 'Este dato es requerido',
                'fecha_inicio' => 'Este dato es requerido',
                'fecha_fin' => 'Este dato es requerido',
                'ingeniero_id' => 'Este dato es requerido',
            ]);

        expect(Proyecto::count())->toBe(0);
    });

    it('rechaza un código ya registrado sin distinguir mayúsculas', function () {
        proyecto(['codigo' => 'PRY-010']);

        $this->actingAs(oficina())->postJson('/api/v1/proyectos', datosProyecto(['codigo' => 'pry-010']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo' => 'El código de proyecto ya existe']);

        expect(Proyecto::count())->toBe(1);
    });

    it('rechaza una fecha de fin igual o anterior a la de inicio', function (string $fin) {
        $this->actingAs(oficina())
            ->postJson('/api/v1/proyectos', datosProyecto(['fecha_inicio' => '2026-11-02', 'fecha_fin' => $fin]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fecha_fin' => 'La fecha de fin debe ser posterior a la de inicio']);

        expect(Proyecto::count())->toBe(0);
    })->with(['2026-11-02', '2026-11-01']);

    it('respeta las longitudes máximas de la pantalla', function () {
        $this->actingAs(oficina())->postJson('/api/v1/proyectos', datosProyecto([
            'codigo' => str_repeat('A', 21),
            'nombre' => str_repeat('N', 121),
            'direccion' => str_repeat('D', 151),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'codigo' => 'Debe tener como máximo 20 caracteres',
                'nombre' => 'Debe tener como máximo 120 caracteres',
                'direccion' => 'Debe tener como máximo 150 caracteres',
            ]);

        $this->actingAs(oficina())->postJson('/api/v1/proyectos', datosProyecto([
            'codigo' => str_repeat('A', 20),
            'nombre' => str_repeat('N', 120),
            'direccion' => str_repeat('D', 150),
        ]))->assertCreated();
    });

    it('solo acepta como responsable a un ingeniero de producción activo', function (Closure $usuario) {
        $this->actingAs(oficina())
            ->postJson('/api/v1/proyectos', datosProyecto(['ingeniero_id' => $usuario()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ingeniero_id']);

        expect(Proyecto::count())->toBe(0);
    })->with([
        'inactivo' => fn () => fn () => User::factory()->rol(Rol::IngenieroProduccion)->inactivo()->create()->id,
        'otro rol' => fn () => fn () => User::factory()->rol(Rol::Almacenero)->create()->id,
        'inexistente' => fn () => fn () => 999999,
    ]);

    it('prohíbe registrar a quien no es oficina técnica', function (Rol $rol) {
        $this->actingAs(User::factory()->rol($rol)->create())
            ->postJson('/api/v1/proyectos', datosProyecto())
            ->assertForbidden();

        expect(Proyecto::count())->toBe(0);
    })->with([Rol::Administrador, Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia]);
});

describe('actualizar', function () {
    it('actualiza los datos y permite finalizar el proyecto', function () {
        $p = proyecto();
        $datos = datosProyecto(['codigo' => 'PRY-001', 'nombre' => 'Los Álamos II', 'estado' => 'Finalizado']);

        $this->actingAs(oficina())->putJson("/api/v1/proyectos/{$p->id}", $datos)
            ->assertOk()
            ->assertJsonPath('id', $p->id)
            ->assertJsonPath('nombre', 'Los Álamos II')
            ->assertJsonPath('estado', 'Finalizado');

        $this->assertDatabaseHas('proyectos', [...$datos, 'id' => $p->id]);
    });

    it('sin estado conserva el que tenía', function () {
        $p = proyecto(['estado' => 'Finalizado']);

        $this->actingAs(oficina())->putJson("/api/v1/proyectos/{$p->id}", datosProyecto())
            ->assertOk()
            ->assertJsonPath('estado', 'Finalizado');
    });

    it('rechaza un estado que no es Activo ni Finalizado', function () {
        $p = proyecto();

        $this->actingAs(oficina())->putJson("/api/v1/proyectos/{$p->id}", datosProyecto(['estado' => 'Cerrado']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['estado']);

        expect($p->fresh()->estado->value)->toBe('Activo');
    });

    it('conserva al ingeniero que quedó inactivo, pero no deja poner a otro inactivo', function () {
        $inactivo = ingeniero(['activo' => false]);
        $p = proyecto(['ingeniero_id' => $inactivo->id]);
        $otroInactivo = ingeniero(['activo' => false]);

        $this->actingAs(oficina())
            ->putJson("/api/v1/proyectos/{$p->id}", datosProyecto(['ingeniero_id' => $inactivo->id]))
            ->assertOk();

        $this->putJson("/api/v1/proyectos/{$p->id}", datosProyecto(['ingeniero_id' => $otroInactivo->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ingeniero_id']);

        expect($p->fresh()->ingeniero_id)->toBe($inactivo->id);
    });

    it('rechaza el código de otro proyecto pero acepta el suyo', function () {
        proyecto(['codigo' => 'PRY-002']);
        $p = proyecto(['codigo' => 'PRY-001']);

        $this->actingAs(oficina())->putJson("/api/v1/proyectos/{$p->id}", datosProyecto(['codigo' => 'PRY-002']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo' => 'El código de proyecto ya existe']);

        $this->putJson("/api/v1/proyectos/{$p->id}", datosProyecto(['codigo' => 'pry-001']))->assertOk();
    });

    it('responde 404 si el proyecto no existe', function () {
        $this->actingAs(oficina())->putJson('/api/v1/proyectos/999999', datosProyecto())->assertNotFound();
    });

    it('prohíbe actualizar a quien no es oficina técnica', function (Rol $rol) {
        $p = proyecto();

        $this->actingAs(User::factory()->rol($rol)->create())
            ->putJson("/api/v1/proyectos/{$p->id}", datosProyecto(['nombre' => 'Otro']))
            ->assertForbidden();

        expect($p->fresh()->nombre)->toBe('Residencial Los Álamos');
    })->with([Rol::Administrador, Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia]);

    it('no ofrece eliminar proyectos', function () {
        $p = proyecto();

        $this->actingAs(oficina())->deleteJson("/api/v1/proyectos/{$p->id}")->assertStatus(405);

        expect(Proyecto::count())->toBe(1);
    });
});

describe('asignados', function () {
    it('devuelve solo los proyectos del usuario de la sesión, con los campos de la fila', function (Rol $rol) {
        $usuario = User::factory()->rol($rol)->create();
        $mio = proyecto(['codigo' => 'PRY-001']);
        proyecto(['codigo' => 'PRY-002']);
        $mio->usuarios()->attach($usuario->id);

        $respuesta = $this->actingAs($usuario)->getJson('/api/v1/proyectos/asignados')->assertOk();

        expect($respuesta->json('*.codigo'))->toBe(['PRY-001']);
        expect(array_keys($respuesta->json('0')))->toEqualCanonicalizing(CAMPOS_FILA);
    })->with(Rol::cases());

    it('sin asignaciones devuelve una lista vacía', function () {
        proyecto();

        $this->actingAs(ingeniero())->getJson('/api/v1/proyectos/asignados')->assertOk()->assertExactJson([]);
    });
});

describe('asignación automática', function () {
    it('al registrar, quien lo crea y su ingeniero lo ven en sus proyectos asignados y entran a él', function () {
        $creador = oficina();
        $ing = ingeniero();

        $id = $this->actingAs($creador)->postJson('/api/v1/proyectos', datosProyecto(['ingeniero_id' => $ing->id]))
            ->assertCreated()
            ->json('id');

        foreach ([$creador, $ing] as $usuario) {
            expect($this->actingAs($usuario)->getJson('/api/v1/proyectos/asignados')->assertOk()->json('*.id'))
                ->toBe([$id]);
        }
        $this->actingAs($creador)->getJson("/api/v1/proyectos/{$id}/partidas")->assertOk();
        $this->actingAs($ing)->getJson("/api/v1/proyectos/{$id}/niveles")->assertOk();
    });

    it('cada rol que puede registrar queda asignado al proyecto que crea', function (Rol $rol) {
        $creador = User::factory()->rol($rol)->create();

        $id = $this->actingAs($creador)->postJson('/api/v1/proyectos', datosProyecto())->assertCreated()->json('id');

        expect($this->getJson('/api/v1/proyectos/asignados')->assertOk()->json('*.id'))->toBe([$id]);
    })->with([Rol::OficinaTecnica]);

    it('a quien no puede registrar le responde 403 y no crea proyecto ni asignaciones', function (Rol $rol) {
        $this->actingAs(User::factory()->rol($rol)->create())
            ->postJson('/api/v1/proyectos', datosProyecto())
            ->assertForbidden();

        expect(Proyecto::count())->toBe(0);
        expect(DB::table('usuario_proyecto')->count())->toBe(0);
    })->with([Rol::Administrador, Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia]);

    it('un tercero no asignado no lo ve ni entra a sus partidas ni a sus niveles', function (Rol $rol) {
        $id = $this->actingAs(oficina())->postJson('/api/v1/proyectos', datosProyecto())->assertCreated()->json('id');
        $tercero = User::factory()->rol($rol)->create();

        $this->actingAs($tercero)->getJson('/api/v1/proyectos/asignados')->assertOk()->assertExactJson([]);
        $this->getJson("/api/v1/proyectos/{$id}/partidas")->assertForbidden()->assertJsonPath('code', 'sin_permiso');
        $this->getJson("/api/v1/proyectos/{$id}/niveles")->assertForbidden()->assertJsonPath('code', 'sin_permiso');
    })->with([Rol::OficinaTecnica, Rol::IngenieroProduccion, Rol::Gerencia]);

    it('al cambiar el ingeniero, el nuevo lo ve y el anterior conserva su acceso', function () {
        $creador = oficina();
        $anterior = ingeniero();
        $nuevo = ingeniero();
        $id = $this->actingAs($creador)->postJson('/api/v1/proyectos', datosProyecto(['ingeniero_id' => $anterior->id]))
            ->assertCreated()
            ->json('id');

        $this->putJson("/api/v1/proyectos/{$id}", datosProyecto(['ingeniero_id' => $nuevo->id]))
            ->assertOk()
            ->assertJsonPath('ingeniero_id', $nuevo->id);

        foreach ([$creador, $anterior, $nuevo] as $usuario) {
            expect($this->actingAs($usuario)->getJson('/api/v1/proyectos/asignados')->assertOk()->json('*.id'))
                ->toBe([$id]);
        }
    });

    it('no duplica filas al editar dos veces con el mismo ingeniero ni si ya estaba asignado', function () {
        $creador = oficina();
        $ing = ingeniero();
        $id = $this->actingAs($creador)->postJson('/api/v1/proyectos', datosProyecto(['ingeniero_id' => $ing->id]))
            ->assertCreated()
            ->json('id');

        $this->putJson("/api/v1/proyectos/{$id}", datosProyecto(['ingeniero_id' => $ing->id]))->assertOk();
        $this->putJson("/api/v1/proyectos/{$id}", datosProyecto(['ingeniero_id' => $ing->id, 'nombre' => 'Otro']))->assertOk();

        expect(DB::table('usuario_proyecto')->where('proyecto_id', $id)->orderBy('usuario_id')->pluck('usuario_id')->all())
            ->toBe(collect([$creador->id, $ing->id])->sort()->values()->all());
    });

    it('editar sin cambiar el ingeniero no le devuelve el acceso que el administrador le quitó', function () {
        $creador = oficina();
        $ing = ingeniero();
        $id = $this->actingAs($creador)->postJson('/api/v1/proyectos', datosProyecto(['ingeniero_id' => $ing->id]))
            ->assertCreated()
            ->json('id');
        // Lo mismo que hace el administrador al quitarle el proyecto desde Usuarios.
        DB::table('usuario_proyecto')->where(['usuario_id' => $ing->id, 'proyecto_id' => $id])->delete();

        $this->putJson("/api/v1/proyectos/{$id}", datosProyecto(['ingeniero_id' => $ing->id, 'nombre' => 'Solo cambia el nombre']))
            ->assertOk();

        expect(DB::table('usuario_proyecto')->where('proyecto_id', $id)->pluck('usuario_id')->all())->toBe([$creador->id]);
        $this->actingAs($ing)->getJson('/api/v1/proyectos/asignados')->assertOk()->assertExactJson([]);
    });

    it('editar a un responsable que quedó inactivo no le devuelve el acceso quitado', function () {
        $creador = oficina();
        $ing = ingeniero();
        $id = $this->actingAs($creador)->postJson('/api/v1/proyectos', datosProyecto(['ingeniero_id' => $ing->id]))
            ->assertCreated()
            ->json('id');
        $ing->update(['activo' => false]);
        DB::table('usuario_proyecto')->where(['usuario_id' => $ing->id, 'proyecto_id' => $id])->delete();

        $this->putJson("/api/v1/proyectos/{$id}", datosProyecto(['ingeniero_id' => $ing->id, 'nombre' => 'Otro']))->assertOk();

        expect(DB::table('usuario_proyecto')->where('proyecto_id', $id)->pluck('usuario_id')->all())->toBe([$creador->id]);
    });

    it('si quien edita es también el responsable, queda una sola fila suya', function () {
        // Solo al editar se puede: se conserva al responsable actual aunque ya no sea ingeniero.
        $editor = oficina();
        $p = proyecto(['ingeniero_id' => $editor->id]);
        $p->usuarios()->attach($editor->id);

        $this->actingAs($editor)->putJson("/api/v1/proyectos/{$p->id}", datosProyecto(['ingeniero_id' => $editor->id]))
            ->assertOk();

        expect(DB::table('usuario_proyecto')->where('proyecto_id', $p->id)->pluck('usuario_id')->all())->toBe([$editor->id]);
    });

    it('no cambia las asignaciones de los demás usuarios ni de otros proyectos', function () {
        $ajeno = User::factory()->rol(Rol::Almacenero)->create();
        $ingOtro = ingeniero();
        $otro = proyecto(['codigo' => 'PRY-001', 'ingeniero_id' => $ingOtro->id]);
        $otro->usuarios()->attach([$ajeno->id, $ingOtro->id]);
        $antes = DB::table('usuario_proyecto')->orderBy('usuario_id')->get(['usuario_id', 'proyecto_id'])->all();

        $creador = oficina();
        $ing = ingeniero();
        $id = $this->actingAs($creador)->postJson('/api/v1/proyectos', datosProyecto(['ingeniero_id' => $ing->id]))
            ->assertCreated()
            ->json('id');
        $this->putJson("/api/v1/proyectos/{$id}", datosProyecto(['ingeniero_id' => $ingOtro->id]))->assertOk();

        expect(DB::table('usuario_proyecto')->where('proyecto_id', '!=', $id)->orderBy('usuario_id')->get(['usuario_id', 'proyecto_id'])->all())
            ->toEqual($antes);
        expect(DB::table('usuario_proyecto')->where('proyecto_id', $id)->orderBy('usuario_id')->pluck('usuario_id')->all())
            ->toBe(collect([$creador->id, $ing->id, $ingOtro->id])->sort()->values()->all());
        expect($this->actingAs($ajeno)->getJson('/api/v1/proyectos/asignados')->json('*.codigo'))->toBe(['PRY-001']);
    });
});

describe('ingenieros', function () {
    it('ofrece solo a los ingenieros de producción activos, con id y nombre y sin datos personales', function () {
        $lucia = ingeniero(['nombres' => 'Lucía', 'apellidos' => 'Ramos']);
        ingeniero(['activo' => false]);
        User::factory()->rol(Rol::Almacenero)->create();

        $this->actingAs(oficina())->getJson('/api/v1/proyectos/ingenieros')
            ->assertOk()
            ->assertExactJson([['id' => $lucia->id, 'nombre' => 'Lucía Ramos']]);
    });

    it('con conservar agrega al ingeniero inactivo de un proyecto existente', function () {
        $activo = ingeniero(['nombres' => 'Ana', 'apellidos' => 'Activa']);
        $inactivo = ingeniero(['nombres' => 'Iván', 'apellidos' => 'Inactivo', 'activo' => false]);
        proyecto(['ingeniero_id' => $inactivo->id]);

        $this->actingAs(oficina())->getJson("/api/v1/proyectos/ingenieros?conservar={$inactivo->id}")
            ->assertOk()
            ->assertExactJson([
                ['id' => $activo->id, 'nombre' => 'Ana Activa'],
                ['id' => $inactivo->id, 'nombre' => 'Iván Inactivo'],
            ]);
    });

    it('conservar no sirve para leer a un usuario que no es responsable de ningún proyecto', function () {
        $admin = User::factory()->rol(Rol::Administrador)->create();
        $inactivo = ingeniero(['activo' => false]);

        foreach ([$admin->id, $inactivo->id, 999999, 'abc'] as $id) {
            $this->actingAs(oficina())->getJson("/api/v1/proyectos/ingenieros?conservar={$id}")
                ->assertOk()
                ->assertExactJson([]);
        }
    });

    it('prohíbe consultar los ingenieros a quien no es oficina técnica', function (Rol $rol) {
        $this->actingAs(User::factory()->rol($rol)->create())
            ->getJson('/api/v1/proyectos/ingenieros')
            ->assertForbidden();
    })->with([Rol::Administrador, Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia]);
});

describe('opciones', function () {
    it('da al administrador solo id y nombre de cada proyecto, para asignarlos a usuarios', function () {
        $p = proyecto();

        $this->actingAs(User::factory()->rol(Rol::Administrador)->create())
            ->getJson('/api/v1/proyectos/opciones')
            ->assertOk()
            ->assertExactJson([['id' => $p->id, 'nombre' => 'Residencial Los Álamos']]);
    });

    it('prohíbe las opciones a los demás perfiles', function (Rol $rol) {
        $this->actingAs(User::factory()->rol($rol)->create())
            ->getJson('/api/v1/proyectos/opciones')
            ->assertForbidden();
    })->with([Rol::Almacenero, Rol::IngenieroProduccion, Rol::OficinaTecnica, Rol::Gerencia]);
});

describe('acceso', function () {
    it('responde 401 a quien no inició sesión', function (string $metodo, string $ruta) {
        $this->json($metodo, $ruta, datosProyecto())->assertUnauthorized();
    })->with([
        ['GET', '/api/v1/proyectos'],
        ['POST', '/api/v1/proyectos'],
        ['PUT', '/api/v1/proyectos/1'],
        ['GET', '/api/v1/proyectos/asignados'],
        ['GET', '/api/v1/proyectos/ingenieros'],
        ['GET', '/api/v1/proyectos/opciones'],
    ]);

    it('exige haber cambiado la contraseña temporal', function () {
        $this->actingAs(User::factory()->rol(Rol::OficinaTecnica)->conPasswordTemporal()->create())
            ->getJson('/api/v1/proyectos')
            ->assertForbidden()
            ->assertJsonPath('code', 'debe_cambiar_password');
    });

    it('con login real por cookie, como la SPA, registra y lista el proyecto', function () {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
        User::factory()->rol(Rol::OficinaTecnica)->create(['email' => 'ot@sicco.test']);
        $datos = datosProyecto();

        $this->postJson('/api/v1/auth/login', ['email' => 'ot@sicco.test', 'password' => 'password'])->assertOk();
        $this->postJson('/api/v1/proyectos', $datos)->assertCreated();
        $this->getJson('/api/v1/proyectos')->assertOk()->assertJsonPath('0.codigo', 'PRY-010');
    });
});

describe('seed de demo', function () {
    it('crea los tres proyectos de ejemplo con su ingeniero y las asignaciones de la pantalla de usuarios', function () {
        $this->seed([RolSeeder::class, UsuarioDemoSeeder::class, ProyectoDemoSeeder::class]);
        $this->seed(ProyectoDemoSeeder::class);

        expect(Proyecto::orderBy('codigo')->pluck('nombre')->all())->toBe([
            'Residencial Los Álamos',
            'Torre Empresarial Javier Prado',
            'Condominio Las Palmeras',
        ]);
        expect(Proyecto::pluck('ingeniero_id')->unique()->all())
            ->toBe([User::where('email', 'ingeniero_produccion@sicco.test')->value('id')]);

        $asignados = fn (string $correo) => User::where('email', $correo)->first();
        foreach ([
            'administrador@sicco.test' => ['PRY-001', 'PRY-002', 'PRY-003'],
            'almacenero@sicco.test' => ['PRY-001'],
            'ingeniero_produccion@sicco.test' => ['PRY-001', 'PRY-002'],
            'oficina_tecnica@sicco.test' => ['PRY-002'],
            'gerencia@sicco.test' => [],
        ] as $correo => $codigos) {
            $this->actingAs($asignados($correo))->getJson('/api/v1/proyectos/asignados')
                ->assertOk();
            expect($this->getJson('/api/v1/proyectos/asignados')->json('*.codigo'))->toBe($codigos);
        }
    });
});
