<?php

use App\Enums\EstadoAlerta;
use App\Enums\Rol;
use App\Jobs\EnviarAlertaConsumo;
use App\Mail\AlertaConsumoMail;
use App\Models\Alerta;
use App\Models\Partida;
use App\Models\Proyecto;
use App\Models\Sector;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;

/** Proyecto de obraConSectores con un sector chico: 10 m³ al 5 % → límite 10.50 m³. */
function obraConSectorChico(): array
{
    [$proyecto, , , $ingeniero] = obraConSectores();
    $chico = sectorDeVaciado(Partida::first(), Sector::first()->nivel_id, 'Sector C', 10);

    return [$proyecto, $chico, $ingeniero];
}

function alertaDe(Sector $sector, array $atributos = []): Alerta
{
    return Alerta::create([
        'proyecto_id' => $sector->nivel->proyecto_id,
        'sector_id' => $sector->id,
        'exceso_m3' => 1.2,
        'fecha' => now(),
        'estado' => EstadoAlerta::Pendiente,
        ...$atributos,
    ]);
}

beforeEach(fn () => Queue::fake());

describe('registro de la alerta (CU14 pasos 1–3)', function () {
    it('la registra con proyecto, sector, exceso y fecha cuando la asignación pasa el límite', function () {
        [$p, $chico, $ingeniero] = obraConSectorChico();

        $this->actingAs($ingeniero);
        repartirGuia(guiaDe($p, ['volumen_m3' => 12]), [$chico->id => 11.7])
            ->assertOk()
            ->assertJsonPath('alertas_registradas', 1);

        $alerta = Alerta::sole();
        expect($alerta->proyecto_id)->toBe($p->id)
            ->and($alerta->sector_id)->toBe($chico->id)
            ->and($alerta->exceso_m3)->toBe('1.20')
            ->and($alerta->estado)->toBe(EstadoAlerta::Pendiente)
            ->and($alerta->fecha->isToday())->toBeTrue()
            ->and($alerta->correo_enviado_at)->toBeNull();
        Queue::assertPushed(EnviarAlertaConsumo::class, fn ($job) => $job->alertaId === $alerta->id);
    });

    it('no la registra si el sector queda justo en el límite', function () {
        [$p, $chico, $ingeniero] = obraConSectorChico();

        $this->actingAs($ingeniero);
        repartirGuia(guiaDe($p, ['volumen_m3' => 10.5]), [$chico->id => 10.5])
            ->assertOk()
            ->assertJsonPath('alertas_registradas', 0);

        expect(Alerta::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('mantiene una sola alerta pendiente por sector y le actualiza el exceso', function () {
        [$p, $chico, $ingeniero] = obraConSectorChico();

        $this->actingAs($ingeniero);
        repartirGuia(guiaDe($p, ['volumen_m3' => 11]), [$chico->id => 11])->assertJsonPath('alertas_registradas', 1);
        repartirGuia(guiaDe($p, ['volumen_m3' => 2]), [$chico->id => 2])->assertJsonPath('alertas_registradas', 0);

        expect(Alerta::sole()->exceso_m3)->toBe('2.50');
        Queue::assertPushed(EnviarAlertaConsumo::class, 1);
    });

    it('registra una alerta nueva si el sector vuelve a crecer después de revisada', function () {
        [$p, $chico, $ingeniero] = obraConSectorChico();
        alertaDe($chico, ['estado' => EstadoAlerta::Revisada, 'revisada_at' => now()]);
        $chico->forceFill(['volumen_vaciado_m3' => 11])->save();

        $this->actingAs($ingeniero);
        repartirGuia(guiaDe($p, ['volumen_m3' => 1]), [$chico->id => 1])->assertJsonPath('alertas_registradas', 1);

        expect(Alerta::pendientes()->sole()->exceso_m3)->toBe('1.50');
    });

    it('una corrección que baja el vaciado no registra alertas y conserva la pendiente', function () {
        [$p, $chico, $ingeniero] = obraConSectorChico();
        $guia = guiaDe($p, ['volumen_m3' => 12]);

        $this->actingAs($ingeniero);
        repartirGuia($guia, [$chico->id => 12])->assertJsonPath('alertas_registradas', 1);
        // Baja a 11 m³: sigue pasado, la pendiente se ajusta.
        repartirGuia($guia, [$chico->id => 11])->assertJsonPath('alertas_registradas', 0);
        expect(Alerta::sole()->exceso_m3)->toBe('0.50');
        // Baja a 9 m³: ya no pasa, la alerta queda como registro hasta que la revisen.
        repartirGuia($guia, [$chico->id => 9])->assertJsonPath('alertas_registradas', 0);

        expect(Alerta::sole())->estado->toBe(EstadoAlerta::Pendiente)->exceso_m3->toBe('0.50');
        Queue::assertPushed(EnviarAlertaConsumo::class, 1);
    });

    it('no registra alertas si la asignación no se guarda', function () {
        [$p, $chico, $ingeniero] = obraConSectorChico();

        $this->actingAs($ingeniero);
        repartirGuia(guiaDe($p, ['volumen_m3' => 5]), [$chico->id => 20])->assertUnprocessable();

        expect(Alerta::count())->toBe(0);
    });
});

describe('correo de la alerta (CU14 paso 3)', function () {
    it('lo envía al ingeniero responsable y a gerencia del proyecto, y lo marca enviado', function () {
        Mail::fake();
        [$p, $chico] = obraConSectorChico();
        $gerente = asignadoA($p, Rol::Gerencia);
        $inactivo = asignadoA($p, Rol::Gerencia);
        $inactivo->update(['activo' => false]);
        conRol(Rol::Gerencia); // de otro proyecto
        $chico->forceFill(['volumen_vaciado_m3' => 11.7])->save();
        $alerta = alertaDe($chico);

        (new EnviarAlertaConsumo($alerta->id))->handle();

        Mail::assertSent(AlertaConsumoMail::class, function (AlertaConsumoMail $mail) use ($p, $gerente) {
            return $mail->hasTo($p->ingeniero->email)
                && $mail->hasTo($gerente->email)
                && count($mail->to) === 2;
        });
        expect($alerta->refresh()->correo_enviado_at)->not->toBeNull();
    });

    it('arma el correo con el detalle del sector', function () {
        [$p, $chico] = obraConSectorChico();
        $chico->forceFill(['volumen_vaciado_m3' => 11.7])->save();
        $alerta = alertaDe($chico)->load(['proyecto', 'sector.nivel', 'sector.partida.resistencia']);

        (new AlertaConsumoMail($alerta))
            ->assertHasSubject('Alerta de consumo: Piso 1 – Sector C (PRY-001)')
            ->assertSeeInHtml('1.20 m³', false)
            ->assertSeeInHtml('10.50 m³', false)
            ->assertSeeInHtml('11.70 m³', false);
    });

    it('si el correo falla, la alerta sigue pendiente de envío y el trabajo se reintenta', function () {
        [, $chico] = obraConSectorChico();
        $alerta = alertaDe($chico);
        Mail::shouldReceive('to')->andThrow(new TransportException('SMTP caído'));

        expect(fn () => (new EnviarAlertaConsumo($alerta->id))->handle())->toThrow(TransportException::class);

        expect($alerta->refresh()->correo_enviado_at)->toBeNull();
        $trabajo = new EnviarAlertaConsumo($alerta->id);
        expect($trabajo->tries)->toBeGreaterThan(1)->and($trabajo->backoff)->not->toBeEmpty();
    });

    it('no lo repite si ya salió', function () {
        Mail::fake();
        [, $chico] = obraConSectorChico();
        $alerta = alertaDe($chico, ['correo_enviado_at' => now()]);

        (new EnviarAlertaConsumo($alerta->id))->handle();

        Mail::assertNothingSent();
    });

    it('el comando programado vuelve a encolar los correos no enviados', function () {
        [, $chico] = obraConSectorChico();
        $atrasada = alertaDe($chico);
        $atrasada->forceFill(['created_at' => now()->subHours(3)])->save();
        alertaDe($chico); // recién creada: la cola aún la está reintentando
        alertaDe($chico, ['correo_enviado_at' => now()])->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->artisan('alertas:reenviar-correos')->assertSuccessful();

        Queue::assertPushed(EnviarAlertaConsumo::class, 1);
        Queue::assertPushed(EnviarAlertaConsumo::class, fn ($job) => $job->alertaId === $atrasada->id);
    });
});

describe('pantalla de alertas (CU14 pasos 4–5)', function () {
    it('lista las alertas de los proyectos del usuario, de la más reciente a la más antigua', function () {
        [$p, $chico, $ingeniero] = obraConSectorChico();
        $antigua = alertaDe($chico, ['fecha' => now()->subDay(), 'estado' => EstadoAlerta::Revisada]);
        $reciente = alertaDe($chico);
        [$otro] = obraConSectoresEn(obra(['codigo' => 'PRY-002']));
        alertaDe($otro);

        $this->actingAs($ingeniero)
            ->getJson('/api/v1/alertas')
            ->assertOk()
            ->assertJsonPath('*.id', [$reciente->id, $antigua->id])
            ->assertJsonPath('0.proyecto.codigo', 'PRY-001')
            ->assertJsonPath('0.sector.nombre', 'Sector C')
            ->assertJsonPath('0.sector.nivel', 'Piso 1')
            ->assertJsonPath('0.sector.limite_m3', 10.5)
            ->assertJsonPath('0.exceso_m3', 1.2)
            ->assertJsonPath('0.estado', 'pendiente')
            ->assertJsonPath('0.correo_enviado_at', null);
    });

    it('filtra por estado y por proyecto', function () {
        [$p, $chico, $ingeniero] = obraConSectorChico();
        $pendiente = alertaDe($chico);
        alertaDe($chico, ['estado' => EstadoAlerta::Revisada]);

        $this->actingAs($ingeniero);
        $this->getJson('/api/v1/alertas?estado=pendiente')->assertJsonPath('*.id', [$pendiente->id]);
        $this->getJson("/api/v1/alertas?proyecto_id={$p->id}")->assertJsonCount(2);
        $this->getJson('/api/v1/alertas?estado=otro')->assertUnprocessable();
    });

    it('cuenta las pendientes para el ícono de notificaciones', function () {
        [, $chico, $ingeniero] = obraConSectorChico();
        alertaDe($chico);
        alertaDe($chico);
        alertaDe($chico, ['estado' => EstadoAlerta::Revisada]);

        $this->actingAs($ingeniero)
            ->getJson('/api/v1/alertas/resumen')
            ->assertOk()
            ->assertExactJson(['pendientes' => 2]);
    });

    it('la marca como revisada con quién y cuándo', function (Rol $rol) {
        [$p, $chico] = obraConSectorChico();
        $usuario = asignadoA($p, $rol);
        $alerta = alertaDe($chico);

        $this->actingAs($usuario)
            ->patchJson("/api/v1/alertas/{$alerta->id}/revisar")
            ->assertOk()
            ->assertJsonPath('message', 'Alerta marcada como revisada')
            ->assertJsonPath('alerta.estado', 'revisada')
            ->assertJsonPath('alerta.revisada_por', $usuario->nombre_completo);

        expect($alerta->refresh())
            ->estado->toBe(EstadoAlerta::Revisada)
            ->revisada_por->toBe($usuario->id)
            ->revisada_at->not->toBeNull();
    })->with([Rol::IngenieroProduccion, Rol::Gerencia]);

    it('no la vuelve a marcar si ya está revisada', function () {
        [, $chico, $ingeniero] = obraConSectorChico();
        $alerta = alertaDe($chico, ['estado' => EstadoAlerta::Revisada, 'revisada_at' => now()]);

        $this->actingAs($ingeniero)
            ->patchJson("/api/v1/alertas/{$alerta->id}/revisar")
            ->assertConflict()
            ->assertJsonPath('message', 'La alerta ya fue revisada');
    });

    it('no deja revisar alertas de otro proyecto', function () {
        [, $chico] = obraConSectorChico();
        $ajeno = asignadoA(obra(['codigo' => 'PRY-002']), Rol::IngenieroProduccion);

        $this->actingAs($ajeno)
            ->patchJson('/api/v1/alertas/'.alertaDe($chico)->id.'/revisar')
            ->assertForbidden();
    });

    it('el administrador las consulta pero no las revisa', function () {
        [, $chico] = obraConSectorChico();
        $alerta = alertaDe($chico);
        $admin = conRol(Rol::Administrador);

        $this->actingAs($admin)->getJson('/api/v1/alertas')->assertOk()->assertJsonCount(1);
        $this->actingAs($admin)->patchJson("/api/v1/alertas/{$alerta->id}/revisar")->assertForbidden();
    });

    it('no las ven Almacenero ni Oficina técnica', function (Rol $rol) {
        [$p] = obraConSectorChico();

        $this->actingAs(asignadoA($p, $rol))->getJson('/api/v1/alertas')->assertForbidden();
        $this->actingAs(asignadoA($p, $rol))->getJson('/api/v1/alertas/resumen')->assertForbidden();
    })->with([Rol::Almacenero, Rol::OficinaTecnica]);
});

/** Un sector en otro proyecto, para comprobar que no se mezclan. */
function obraConSectoresEn(Proyecto $proyecto): array
{
    $partida = $proyecto->partidas()->create(datosPartida());
    $nivel = $proyecto->niveles()->create(['nombre' => 'Piso 1', 'orden' => 1]);

    return [sectorDeVaciado($partida, $nivel->id, 'Sector X')];
}
