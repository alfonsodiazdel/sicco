<?php

use App\Enums\Rol;
use App\Mail\PasswordTemporalMail;
use App\Models\Proyecto;
use App\Models\User;
use App\Support\PasswordTemporal;
use Database\Seeders\RolSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

const PASSWORD_TEMPORAL_FIJA = 'Temporal2026x';
const CAMPOS_USUARIO = ['id', 'nombres', 'apellidos', 'email', 'rol', 'activo', 'proyecto_ids'];
const NO_ADMINISTRADORES = [Rol::Almacenero, Rol::IngenieroProduccion, Rol::OficinaTecnica, Rol::Gerencia];

beforeEach(function () {
    $this->seed(RolSeeder::class);
    // Valor fijo para poder buscarlo en respuestas, correos y logs.
    $this->app->bind(PasswordTemporal::class, fn () => new class extends PasswordTemporal
    {
        public function generar(): string
        {
            return PASSWORD_TEMPORAL_FIJA;
        }
    });
});

function administrador(array $atributos = []): User
{
    return User::factory()->rol(Rol::Administrador)->create($atributos);
}

function proyectoAsignable(string $codigo): Proyecto
{
    return Proyecto::where('codigo', $codigo)->first() ?? Proyecto::create([
        'codigo' => $codigo,
        'nombre' => "Proyecto {$codigo}",
        'direccion' => 'Av. Arequipa 1200, Lima',
        'fecha_inicio' => '2026-03-02',
        'fecha_fin' => '2027-02-26',
        'ingeniero_id' => User::factory()->rol(Rol::IngenieroProduccion)->create()->id,
        'estado' => 'Activo',
    ]);
}

function datosUsuario(array $cambios = []): array
{
    return [
        'nombres' => 'Lucía',
        'apellidos' => 'Ramos Pérez',
        'email' => 'lucia.ramos@sicco.test',
        'rol' => 'ingeniero_produccion',
        'proyecto_ids' => $cambios['proyecto_ids'] ?? [proyectoAsignable('PRY-900')->id],
        ...$cambios,
    ];
}

function asignar(User $usuario, Proyecto ...$proyectos): void
{
    foreach ($proyectos as $proyecto) {
        $proyecto->usuarios()->attach($usuario->id);
    }
}

function conteosUsuarios(): array
{
    return ['users' => User::count(), 'usuario_proyecto' => DB::table('usuario_proyecto')->count()];
}

function estadoGuardado(User $usuario): array
{
    return [
        ...User::whereKey($usuario->id)->first()->only(['nombres', 'apellidos', 'email', 'rol_id', 'activo', 'password', 'debe_cambiar_password']),
        'proyectos' => DB::table('usuario_proyecto')->where('usuario_id', $usuario->id)->orderBy('proyecto_id')->pluck('proyecto_id')->all(),
    ];
}

function rolId(Rol $rol): int
{
    return App\Models\Rol::where('clave', $rol)->value('id');
}

function sinSecretos(TestResponse $respuesta): void
{
    $claves = array_map(fn (string $clave) => Str::afterLast($clave, '.'), array_keys(Arr::dot((array) $respuesta->json())));

    expect($claves)->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain('debe_cambiar_password');
    expect($respuesta->getContent())->not->toContain(PASSWORD_TEMPORAL_FIJA)->not->toContain('$2y$');
}

function capturarLogs(): ArrayObject
{
    $logs = new ArrayObject;
    Log::listen(fn (MessageLogged $e) => $logs->append($e->message.' '.json_encode(array_map(
        fn ($valor) => $valor instanceof Throwable ? $valor->getMessage().' '.$valor->getTraceAsString() : $valor,
        $e->context,
    ))));

    // Sonda: demuestra que la captura funciona antes de afirmar que algo no está.
    Log::info('sonda-de-logs');
    expect($logs->getArrayCopy())->toHaveCount(1)->and($logs[0])->toContain('sonda-de-logs');

    return $logs;
}

function fallarEnvioDeCorreo(): void
{
    Mail::extend('falla', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection could not be established with host "smtp.invalido:587"');
        }

        public function __toString(): string
        {
            return 'falla://';
        }
    });
    config(['mail.mailers.falla' => ['transport' => 'falla'], 'mail.default' => 'falla']);
}

function cabecerasSpa(): array
{
    return ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'];
}

describe('listar', function () {
    it('devuelve las filas ordenadas por id, con rol, estado y proyectos asignados', function () {
        $p1 = proyectoAsignable('PRY-001');
        $p2 = proyectoAsignable('PRY-002');
        $admin = administrador(['nombres' => 'Rosa', 'apellidos' => 'Salas', 'email' => 'rosa@sicco.test']);
        $ana = User::factory()->rol(Rol::Almacenero)->inactivo()
            ->create(['nombres' => 'Ana', 'apellidos' => 'Quispe', 'email' => 'ana@sicco.test']);
        asignar($ana, $p2, $p1);

        $respuesta = $this->actingAs($admin)->getJson('/api/v1/usuarios')->assertOk();

        expect($respuesta->json('*.id'))->toBe(User::orderBy('id')->pluck('id')->all());
        foreach ($respuesta->json() as $fila) {
            expect(array_keys($fila))->toBe(CAMPOS_USUARIO);
        }
        expect(collect($respuesta->json())->firstWhere('id', $admin->id))->toBe([
            'id' => $admin->id,
            'nombres' => 'Rosa',
            'apellidos' => 'Salas',
            'email' => 'rosa@sicco.test',
            'rol' => 'administrador',
            'activo' => true,
            'proyecto_ids' => [],
        ]);
        expect(collect($respuesta->json())->firstWhere('id', $ana->id))->toBe([
            'id' => $ana->id,
            'nombres' => 'Ana',
            'apellidos' => 'Quispe',
            'email' => 'ana@sicco.test',
            'rol' => 'almacenero',
            'activo' => false,
            'proyecto_ids' => [$p1->id, $p2->id],
        ]);
        sinSecretos($respuesta);
    });

    it('usa las mismas consultas con 1 o con 6 usuarios asignados (sin N+1)', function () {
        $admin = administrador();
        $p = proyectoAsignable('PRY-001');
        $consultas = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin->fresh())->getJson('/api/v1/usuarios')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $base = $consultas();
        User::factory()->count(5)->rol(Rol::Almacenero)->create()->each(fn (User $u) => asignar($u, $p));

        expect($consultas())->toBe($base);
    });
});

describe('crear', function () {
    it('registra al usuario con contraseña temporal, sus proyectos y le envía el correo', function () {
        Mail::fake();
        config(['app.frontend_url' => 'https://sicco.example']);
        $p1 = proyectoAsignable('PRY-001');
        $p2 = proyectoAsignable('PRY-002');

        $respuesta = $this->actingAs(administrador())
            ->postJson('/api/v1/usuarios', datosUsuario(['proyecto_ids' => [$p2->id, $p1->id]]))
            ->assertCreated();

        $nuevo = User::where('email', 'lucia.ramos@sicco.test')->firstOrFail();
        expect($respuesta->json())->toBe([
            'id' => $nuevo->id,
            'nombres' => 'Lucía',
            'apellidos' => 'Ramos Pérez',
            'email' => 'lucia.ramos@sicco.test',
            'rol' => 'ingeniero_produccion',
            'activo' => true,
            'proyecto_ids' => [$p1->id, $p2->id],
        ]);
        expect($nuevo->rol_id)->toBe(rolId(Rol::IngenieroProduccion))
            ->and($nuevo->activo)->toBeTrue()
            ->and($nuevo->debe_cambiar_password)->toBeTrue()
            ->and(Hash::check(PASSWORD_TEMPORAL_FIJA, $nuevo->password))->toBeTrue();
        expect(estadoGuardado($nuevo)['proyectos'])->toBe([$p1->id, $p2->id]);
        sinSecretos($respuesta);

        Mail::assertSentCount(1);
        Mail::assertSent(PasswordTemporalMail::class, function (PasswordTemporalMail $correo) {
            $html = $correo->render();

            return $correo->hasTo('lucia.ramos@sicco.test')
                && str_contains($html, PASSWORD_TEMPORAL_FIJA)
                && str_contains($html, 'Lucía')
                && str_contains($html, 'lucia.ramos@sicco.test')
                && str_contains($html, 'https://sicco.example')
                && str_contains($html, 'cambiar');
        });
    });

    it('ignora la asignación masiva de password, activo, debe_cambiar_password, rol_id e id', function () {
        Mail::fake();

        $respuesta = $this->actingAs(administrador())->postJson('/api/v1/usuarios', datosUsuario([
            'id' => 999999,
            'password' => 'Intrusa2026',
            'activo' => false,
            'debe_cambiar_password' => false,
            'rol_id' => rolId(Rol::Administrador),
        ]))->assertCreated();

        $nuevo = User::where('email', 'lucia.ramos@sicco.test')->firstOrFail();
        expect($respuesta->json('id'))->not->toBe(999999)
            ->and($nuevo->activo)->toBeTrue()
            ->and($nuevo->debe_cambiar_password)->toBeTrue()
            ->and($nuevo->rol_id)->toBe(rolId(Rol::IngenieroProduccion))
            ->and(Hash::check('Intrusa2026', $nuevo->password))->toBeFalse()
            ->and(Hash::check(PASSWORD_TEMPORAL_FIJA, $nuevo->password))->toBeTrue();
    });

    it('exige cada campo con el mensaje de la tesis, también proyectos vacíos', function (array $cuerpo) {
        Mail::fake();
        $admin = administrador();
        $antes = conteosUsuarios();

        $this->actingAs($admin)->postJson('/api/v1/usuarios', $cuerpo)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nombres' => 'Este dato es requerido',
                'apellidos' => 'Este dato es requerido',
                'email' => 'Este dato es requerido',
                'rol' => 'Este dato es requerido',
                'proyecto_ids' => 'Este dato es requerido',
            ]);

        expect(conteosUsuarios())->toBe($antes);
        Mail::assertNothingSent();
    })->with([
        'sin campos' => [[]],
        'vacíos' => [['nombres' => '   ', 'apellidos' => '', 'email' => ' ', 'rol' => '', 'proyecto_ids' => []]],
    ]);

    it('respeta las longitudes máximas 100/100/255 justo en el límite', function () {
        Mail::fake();
        // 64 + 1 + 190 = 255 caracteres, con etiquetas de dominio que el validador RFC acepta.
        $correo255 = str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 60).'.com';
        $correo256 = str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 61).'.com';
        expect(strlen($correo255))->toBe(255)->and(strlen($correo256))->toBe(256);
        $admin = administrador();

        $this->actingAs($admin)->postJson('/api/v1/usuarios', datosUsuario([
            'nombres' => str_repeat('N', 101),
            'apellidos' => str_repeat('A', 101),
            'email' => $correo256,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nombres' => 'Debe tener como máximo 100 caracteres',
                'apellidos' => 'Debe tener como máximo 100 caracteres',
                'email' => 'Debe tener como máximo 255 caracteres',
            ]);
        expect(User::count())->toBe(2);

        $this->postJson('/api/v1/usuarios', datosUsuario([
            'nombres' => str_repeat('N', 100),
            'apellidos' => str_repeat('A', 100),
            'email' => $correo255,
        ]))->assertCreated()->assertJsonPath('email', $correo255);
    });

    it('rechaza un correo ya registrado, sin distinguir mayúsculas', function (string $correo) {
        Mail::fake();
        User::factory()->create(['email' => 'lucia.ramos@sicco.test']);
        $admin = administrador();
        $datos = datosUsuario(['email' => $correo]);
        $antes = conteosUsuarios();

        $this->actingAs($admin)->postJson('/api/v1/usuarios', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'El correo ya está registrado']);

        expect(conteosUsuarios())->toBe($antes);
        Mail::assertNothingSent();
    })->with(['lucia.ramos@sicco.test', 'LUCIA.Ramos@SICCO.test']);

    it('rechaza un correo sin dominio válido', function (string $correo) {
        $this->actingAs(administrador())->postJson('/api/v1/usuarios', datosUsuario(['email' => $correo]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Ingrese un correo válido, por ejemplo nombre@empresa.com.']);

        expect(User::where('email', $correo)->exists())->toBeFalse();
    })->with(['lucia@sicco', 'no-es-correo', 'lucia @sicco.test']);

    it('rechaza un rol que no existe', function () {
        $admin = administrador();
        $datos = datosUsuario(['rol' => 'superadmin']);
        $antes = conteosUsuarios();

        $this->actingAs($admin)->postJson('/api/v1/usuarios', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rol' => 'Seleccione un rol válido']);

        expect(conteosUsuarios())->toBe($antes);
    });

    it('solo asigna proyectos existentes, sin repetir', function (Closure $ids, string $error) {
        Mail::fake();
        $admin = administrador();
        $ids = $ids();
        $antes = conteosUsuarios();

        $respuesta = $this->actingAs($admin)->postJson('/api/v1/usuarios', datosUsuario(['proyecto_ids' => $ids]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$error]);

        expect(array_keys($respuesta->json('errors')))->each->toStartWith('proyecto_ids');
        expect(conteosUsuarios())->toBe($antes);
        Mail::assertNothingSent();
    })->with([
        'inexistente' => [fn () => [proyectoAsignable('PRY-001')->id, 999999], 'proyecto_ids.1'],
        'texto' => [fn () => ['abc'], 'proyecto_ids.0'],
        'repetido' => [fn () => [proyectoAsignable('PRY-001')->id, proyectoAsignable('PRY-001')->id], 'proyecto_ids.1'],
        'no es lista' => [fn () => 'PRY-001', 'proyecto_ids'],
    ]);
});

describe('actualizar', function () {
    it('cambia datos, rol y proyectos sin tocar la contraseña ni el estado', function () {
        Mail::fake();
        $p1 = proyectoAsignable('PRY-001');
        $p2 = proyectoAsignable('PRY-002');
        $usuario = User::factory()->rol(Rol::Almacenero)->conPasswordTemporal()->create();
        asignar($usuario, $p1);
        $hash = $usuario->password;

        $respuesta = $this->actingAs(administrador())->putJson("/api/v1/usuarios/{$usuario->id}", datosUsuario([
            'nombres' => 'Lucía Elena',
            'rol' => 'oficina_tecnica',
            'proyecto_ids' => [$p2->id],
        ]))->assertOk();

        expect($respuesta->json())->toBe([
            'id' => $usuario->id,
            'nombres' => 'Lucía Elena',
            'apellidos' => 'Ramos Pérez',
            'email' => 'lucia.ramos@sicco.test',
            'rol' => 'oficina_tecnica',
            'activo' => true,
            'proyecto_ids' => [$p2->id],
        ]);
        $guardado = estadoGuardado($usuario);
        expect($guardado['rol_id'])->toBe(rolId(Rol::OficinaTecnica))
            ->and($guardado['proyectos'])->toBe([$p2->id])
            ->and($guardado['password'])->toBe($hash)
            ->and($guardado['debe_cambiar_password'])->toBeTrue();
        sinSecretos($respuesta);
        Mail::assertNothingSent();
    });

    it('acepta el correo del propio usuario y rechaza el de otro', function () {
        User::factory()->create(['email' => 'otro@sicco.test']);
        $usuario = User::factory()->create(['email' => 'lucia.ramos@sicco.test']);

        $this->actingAs(administrador())
            ->putJson("/api/v1/usuarios/{$usuario->id}", datosUsuario(['email' => 'LUCIA.ramos@sicco.test']))
            ->assertOk();

        $this->putJson("/api/v1/usuarios/{$usuario->id}", datosUsuario(['email' => 'otro@sicco.test']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'El correo ya está registrado']);
    });

    it('ignora la asignación masiva al editar', function () {
        $usuario = User::factory()->rol(Rol::Almacenero)->create();
        $hash = $usuario->password;

        $this->actingAs(administrador())->putJson("/api/v1/usuarios/{$usuario->id}", datosUsuario([
            'rol' => 'almacenero',
            'id' => 999999,
            'password' => 'Intrusa2026',
            'activo' => false,
            'debe_cambiar_password' => true,
            'rol_id' => rolId(Rol::Administrador),
        ]))->assertOk()->assertJsonPath('id', $usuario->id);

        expect(estadoGuardado($usuario))->toMatchArray([
            'rol_id' => rolId(Rol::Almacenero),
            'activo' => true,
            'debe_cambiar_password' => false,
            'password' => $hash,
        ]);
    });

    it('valida igual que el alta', function () {
        $usuario = User::factory()->create();
        $antes = estadoGuardado($usuario);

        $this->actingAs(administrador())->putJson("/api/v1/usuarios/{$usuario->id}", ['proyecto_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombres', 'apellidos', 'email', 'rol', 'proyecto_ids' => 'Este dato es requerido']);

        expect(estadoGuardado($usuario))->toBe($antes);
    });

    it('responde 404 si el usuario no existe y no ofrece eliminar', function () {
        $usuario = User::factory()->create();
        $this->actingAs(administrador())->putJson('/api/v1/usuarios/999999', datosUsuario())->assertNotFound();
        $this->patchJson('/api/v1/usuarios/999999/estado', ['activo' => false])->assertNotFound();
        $this->deleteJson("/api/v1/usuarios/{$usuario->id}")->assertStatus(405);

        expect(User::whereKey($usuario->id)->exists())->toBeTrue();
    });
});

describe('estado', function () {
    it('desactiva y reactiva sin tocar la contraseña ni los demás datos', function () {
        $usuario = User::factory()->rol(Rol::IngenieroProduccion)->create();
        asignar($usuario, proyectoAsignable('PRY-001'));
        $antes = estadoGuardado($usuario);

        $respuesta = $this->actingAs(administrador())
            ->patchJson("/api/v1/usuarios/{$usuario->id}/estado", ['activo' => false, 'nombres' => 'Otro', 'rol' => 'administrador', 'password' => 'Intrusa2026'])
            ->assertOk()
            ->assertJsonPath('activo', false)
            ->assertJsonPath('rol', 'ingeniero_produccion');
        expect(array_keys($respuesta->json()))->toBe(CAMPOS_USUARIO);
        sinSecretos($respuesta);
        expect(estadoGuardado($usuario))->toBe([...$antes, 'activo' => false]);

        $this->patchJson("/api/v1/usuarios/{$usuario->id}/estado", ['activo' => true])
            ->assertOk()
            ->assertJsonPath('activo', true);
        expect(estadoGuardado($usuario))->toBe($antes);
    });

    it('exige activo verdadero o falso', function (array $cuerpo) {
        $usuario = User::factory()->create();

        $this->actingAs(administrador())->patchJson("/api/v1/usuarios/{$usuario->id}/estado", $cuerpo)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['activo']);

        expect($usuario->fresh()->activo)->toBeTrue();
    })->with([[[]], [['activo' => 'quizás']]]);
});

describe('guardas', function () {
    it('nadie se cambia el rol a sí mismo', function () {
        $admin = administrador();
        $antes = estadoGuardado($admin);

        $this->actingAs($admin)->putJson("/api/v1/usuarios/{$admin->id}", datosUsuario(['rol' => 'gerencia']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rol' => 'No puede cambiar su propio rol.']);

        expect(estadoGuardado($admin))->toBe($antes);
    });

    it('el administrador sí edita su propio nombre manteniendo su rol', function () {
        $admin = administrador();

        $this->actingAs($admin)
            ->putJson("/api/v1/usuarios/{$admin->id}", datosUsuario(['nombres' => 'Rosa María', 'rol' => 'administrador']))
            ->assertOk()
            ->assertJsonPath('nombres', 'Rosa María')
            ->assertJsonPath('rol', 'administrador');
    });

    it('el administrador no se desactiva a sí mismo', function () {
        $admin = administrador();
        administrador();
        $antes = estadoGuardado($admin);

        $this->actingAs($admin)->patchJson("/api/v1/usuarios/{$admin->id}/estado", ['activo' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['activo' => 'No puede desactivar su propio usuario.']);

        expect(estadoGuardado($admin))->toBe($antes);
    });

    it('deja desactivar o degradar a otro administrador mientras quede uno activo', function () {
        $admin = administrador();
        $otro = administrador();
        $tercero = administrador();

        $this->actingAs($admin)->patchJson("/api/v1/usuarios/{$otro->id}/estado", ['activo' => false])->assertOk();
        $this->putJson("/api/v1/usuarios/{$tercero->id}", datosUsuario(['rol' => 'gerencia']))->assertOk();
    });

    /*
     * La sesión del administrador ya pasó los middlewares cuando otro administrador lo
     * desactiva: es la carrera entre dos administradores que se desactivan entre sí.
     */
    it('no deja el sistema sin administradores activos', function (string $metodo, Closure $ruta, array $cuerpo, string $campo) {
        $admin = administrador();
        $otro = administrador();
        User::whereKey($admin->id)->update(['activo' => false]);
        $antes = estadoGuardado($otro);

        $this->actingAs($admin)->json($metodo, $ruta($otro), $cuerpo === [] ? datosUsuario(['rol' => 'oficina_tecnica']) : $cuerpo)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$campo => 'Debe quedar al menos un administrador activo.']);

        expect(estadoGuardado($otro))->toBe($antes);
    })->with([
        'desactivando' => ['PATCH', fn (User $u) => "/api/v1/usuarios/{$u->id}/estado", ['activo' => false], 'activo'],
        'degradando' => ['PUT', fn (User $u) => "/api/v1/usuarios/{$u->id}", [], 'rol'],
    ]);
});

describe('acceso', function () {
    it('prohíbe gestionar usuarios a los demás perfiles sin escribir nada', function (Rol $rol) {
        $objetivo = User::factory()->rol(Rol::IngenieroProduccion)->create();
        asignar($objetivo, proyectoAsignable('PRY-001'));
        $actor = User::factory()->rol($rol)->create();
        $datos = datosUsuario(['rol' => 'administrador']);
        $antes = [conteosUsuarios(), estadoGuardado($objetivo)];

        $this->actingAs($actor);
        foreach ([
            $this->getJson('/api/v1/usuarios'),
            $this->postJson('/api/v1/usuarios', $datos),
            $this->putJson("/api/v1/usuarios/{$objetivo->id}", $datos),
            $this->patchJson("/api/v1/usuarios/{$objetivo->id}/estado", ['activo' => false]),
            $this->putJson("/api/v1/usuarios/{$actor->id}", $datos),
        ] as $respuesta) {
            $respuesta->assertForbidden()->assertJsonPath('code', 'sin_permiso');
        }

        expect([conteosUsuarios(), estadoGuardado($objetivo)])->toBe($antes);
        expect($actor->fresh()->rol_id)->toBe(rolId($rol));
    })->with(NO_ADMINISTRADORES);

    it('responde 401 a quien no inició sesión', function (string $metodo, string $ruta) {
        $datos = datosUsuario();
        $antes = conteosUsuarios();

        $this->json($metodo, $ruta, $datos)->assertUnauthorized();

        expect(conteosUsuarios())->toBe($antes);
    })->with([
        ['GET', '/api/v1/usuarios'],
        ['POST', '/api/v1/usuarios'],
        ['PUT', '/api/v1/usuarios/1'],
        ['PATCH', '/api/v1/usuarios/1/estado'],
    ]);

    it('exige al administrador haber cambiado su contraseña temporal', function () {
        $admin = User::factory()->rol(Rol::Administrador)->conPasswordTemporal()->create();
        $datos = datosUsuario();
        $antes = conteosUsuarios();

        $this->actingAs($admin);
        foreach ([
            $this->getJson('/api/v1/usuarios'),
            $this->postJson('/api/v1/usuarios', $datos),
            $this->putJson("/api/v1/usuarios/{$admin->id}", $datos),
            $this->patchJson("/api/v1/usuarios/{$admin->id}/estado", ['activo' => true]),
        ] as $respuesta) {
            $respuesta->assertForbidden()->assertJsonPath('code', 'debe_cambiar_password');
        }

        expect(conteosUsuarios())->toBe($antes);
    });
});

describe('contraseña temporal', function () {
    it('nunca se devuelve en ninguna respuesta', function () {
        Mail::fake();
        $admin = administrador();
        $this->actingAs($admin);

        $alta = $this->postJson('/api/v1/usuarios', datosUsuario())->assertCreated();
        $id = $alta->json('id');

        foreach ([
            $alta,
            $this->getJson('/api/v1/usuarios')->assertOk(),
            $this->putJson("/api/v1/usuarios/{$id}", datosUsuario(['nombres' => 'Otra']))->assertOk(),
            $this->patchJson("/api/v1/usuarios/{$id}/estado", ['activo' => false])->assertOk(),
            $this->postJson('/api/v1/usuarios', datosUsuario())->assertUnprocessable(),
        ] as $respuesta) {
            sinSecretos($respuesta);
        }
    });

    it('la genera el servidor cumpliendo la política de contraseñas', function () {
        $generadas = collect(range(1, 20))->map(fn () => (new PasswordTemporal)->generar());

        $generadas->each(fn (string $p) => expect(Validator::make(['p' => $p], ['p' => Password::defaults()])->passes())->toBeTrue());
        expect($generadas->unique())->toHaveCount(20);
    });

    it('no la escribe en los logs cuando el alta sale bien', function () {
        $logs = capturarLogs();

        $this->actingAs(administrador())->postJson('/api/v1/usuarios', datosUsuario())->assertCreated();

        expect(implode("\n", $logs->getArrayCopy()))->not->toContain(PASSWORD_TEMPORAL_FIJA);
    });
});

describe('correo que falla', function () {
    it('revierte el alta, avisa con 503 y no deja la contraseña en la respuesta ni en los logs', function () {
        $admin = administrador();
        $p = proyectoAsignable('PRY-001');
        fallarEnvioDeCorreo();
        $logs = capturarLogs();
        $antes = conteosUsuarios();

        $respuesta = $this->actingAs($admin)
            ->postJson('/api/v1/usuarios', datosUsuario(['proyecto_ids' => [$p->id]]))
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'No se pudo enviar la contraseña temporal al correo. El usuario no se registró; inténtelo de nuevo.',
                'code' => 'correo_no_enviado',
            ]);

        expect(conteosUsuarios())->toBe($antes)
            ->and(User::where('email', 'lucia.ramos@sicco.test')->exists())->toBeFalse();
        expect($respuesta->getContent())->not->toContain(PASSWORD_TEMPORAL_FIJA);

        $texto = implode("\n", $logs->getArrayCopy());
        expect($texto)->toContain('smtp.invalido')
            ->not->toContain(PASSWORD_TEMPORAL_FIJA);
    });
});

describe('flujo de inicio de sesión', function () {
    it('el usuario nuevo entra con la contraseña temporal, debe cambiarla y ve solo sus proyectos', function () {
        $p1 = proyectoAsignable('PRY-001');
        proyectoAsignable('PRY-002');
        $p3 = proyectoAsignable('PRY-003');

        $this->actingAs(administrador())
            ->postJson('/api/v1/usuarios', datosUsuario(['email' => 'nuevo@sicco.test', 'proyecto_ids' => [$p3->id, $p1->id]]))
            ->assertCreated();

        // Otro navegador: sin la sesión del administrador.
        Auth::forgetGuards();
        $this->withHeaders(cabecerasSpa());

        $this->postJson('/api/v1/auth/login', ['email' => 'nuevo@sicco.test', 'password' => PASSWORD_TEMPORAL_FIJA])
            ->assertOk()
            ->assertJsonPath('debe_cambiar_password', true);
        $this->getJson('/api/v1/proyectos/asignados')
            ->assertForbidden()
            ->assertJsonPath('code', 'debe_cambiar_password');

        $this->putJson('/api/v1/auth/password', [
            'password_actual' => PASSWORD_TEMPORAL_FIJA,
            'password' => 'NuevaClave2026',
            'password_confirmation' => 'NuevaClave2026',
        ])->assertOk();

        expect($this->getJson('/api/v1/proyectos/asignados')->assertOk()->json('*.codigo'))->toBe(['PRY-001', 'PRY-003']);
    });

    it('un ingeniero desactivado no entra ni conserva su sesión; reactivado vuelve a entrar', function () {
        $admin = administrador();
        $ing = User::factory()->rol(Rol::IngenieroProduccion)->create(['email' => 'ing@sicco.test']);
        $credenciales = ['email' => 'ing@sicco.test', 'password' => 'password'];
        $cambiarEstado = function (bool $activo) use ($admin, $ing) {
            Auth::forgetGuards();
            $this->actingAs($admin->fresh())->patchJson("/api/v1/usuarios/{$ing->id}/estado", ['activo' => $activo])->assertOk();
            Auth::forgetGuards();
        };
        $this->withHeaders(cabecerasSpa());

        $sesion = $this->postJson('/api/v1/auth/login', $credenciales)->assertOk()
            ->getCookie(config('session.cookie'))->getValue();
        Auth::forgetGuards();
        // La cookie sola abre la sesión: el usuario se vuelve a leer de la base en cada petición.
        $this->withCookie(config('session.cookie'), $sesion)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $ing->id);

        $cambiarEstado(false);

        $this->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'usuario_inactivo');
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', $credenciales)->assertForbidden()->assertJsonPath('code', 'usuario_inactivo');

        $cambiarEstado(true);

        $this->postJson('/api/v1/auth/login', $credenciales)->assertOk()->assertJsonPath('id', $ing->id);
    });
});
