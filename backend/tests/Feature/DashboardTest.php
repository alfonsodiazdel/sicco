<?php

use App\Enums\EstadoAlerta;
use App\Enums\EstadoProyecto;
use App\Enums\Rol;
use App\Models\Alerta;
use App\Models\GrupoPartida;
use App\Models\Proyecto;
use App\Models\ReporteGenerado;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'America/Lima'));
});

/** Proyecto con una partida f'c 210 y un nivel; devuelve [proyecto, partida, nivel]. */
function obraDashboard(string $codigo, float $precio, float $factor, array $atributos = []): array
{
    $proyecto = obra(['codigo' => $codigo, 'nombre' => "Obra {$codigo}", 'fecha_inicio' => '2026-09-01', ...$atributos]);
    $grupo = GrupoPartida::firstOrCreate(['nombre' => 'Estructuras'], ['orden' => 1]);
    $partida = $proyecto->partidas()->create(datosPartida([
        'grupo_id' => $grupo->id, 'elemento' => 'Columnas', 'resistencia_id' => resistencia(210)->id,
        'metrado_m3' => 20, 'precio_unitario' => $precio, 'factor_desperdicio' => $factor,
    ]));

    return [$proyecto, $partida, $proyecto->niveles()->create(['nombre' => 'Piso 1', 'orden' => 1])];
}

function sectorDashboard(array $obra, string $nombre, float $presupuestado, ?string $culminado = null): Sector
{
    [, $partida, $nivel] = $obra;
    $sector = Sector::create(['nivel_id' => $nivel->id, 'presupuesto_id' => $partida->id, 'nombre' => $nombre, 'volumen_presup_m3' => $presupuestado]);
    if ($culminado) {
        $sector->forceFill(['fecha_culminacion' => $culminado])->save();
    }

    return $sector;
}

/**
 * A (OBR-01, 10 %): S1 de 10 m³ culminado el 10-28 con 12 m³ (G1 y G2 de octubre) y S2 abierto con
 * 4 m³ de G3 (noviembre). F1 (10-15) factura G1 a S/ 310.
 * B (OBR-02, 5 %, S/ 280 presupuestado, sin facturas): T1 de 10 m³ culminado el 10-30 con H1 de 10 m³.
 * C finalizado y D sin asignar no se muestran; la factura de D a S/ 999 no cambia el precio de A.
 */
function escenarioDashboard(): array
{
    $r210 = resistencia(210);
    $a = obraDashboard('OBR-01', 300, 10);
    $s1 = sectorDashboard($a, 'S1', 10, '2026-10-28');
    $s2 = sectorDashboard($a, 'S2', 10);
    $f1 = facturaCorte($a[0], 'F001-1', '2026-10-15', [[$r210, 310]], 10, 3100);
    guiaCorte($a[0], 'G1', '2026-10-05', $r210, 6, $f1, [$s1->id => 6]);
    guiaCorte($a[0], 'G2', '2026-10-20', $r210, 6, null, [$s1->id => 6]);
    guiaCorte($a[0], 'G3', '2026-11-03', $r210, 4, null, [$s2->id => 4]);

    $b = obraDashboard('OBR-02', 280, 5);
    $t1 = sectorDashboard($b, 'T1', 10, '2026-10-30');
    guiaCorte($b[0], 'H1', '2026-10-10', $r210, 10, null, [$t1->id => 10]);

    $c = obraDashboard('OBR-03', 300, 10, ['estado' => EstadoProyecto::Finalizado]);
    guiaCorte($c[0], 'K1', '2026-10-10', $r210, 50, null);

    $d = obraDashboard('OBR-04', 300, 10);
    facturaCorte($d[0], 'F009-1', '2026-10-25', [[$r210, 999]], 10, 9990);
    $u1 = sectorDashboard($d, 'U1', 5);

    $gerente = asignadoA($a[0], Rol::Gerencia);
    $b[0]->usuarios()->attach($gerente->id);
    $c[0]->usuarios()->attach($gerente->id);

    return ['gerente' => $gerente, 'a' => $a[0], 'b' => $b[0], 'd' => $d[0], 's1' => $s1, 'u1' => $u1];
}

function dashboard(User $usuario, array $consulta = [])
{
    return test()->actingAs($usuario)->getJson('/api/v1/dashboard?'.http_build_query($consulta));
}

describe('indicadores', function () {
    it('suma vaciado, costo y desperdicio de los proyectos activos asignados en el mes', function () {
        $e = escenarioDashboard();

        $r = dashboard($e['gerente'], ['desde' => '2026-10', 'hasta' => '2026-10'])->assertOk();

        expect($r->json('periodo'))->toEqual([
            'desde' => '2026-10', 'hasta' => '2026-10',
            'fecha_inicio' => '2026-10-01', 'fecha_cierre' => '2026-10-31', 'parcial' => false,
        ])
            // Desperdicio global ponderado: (22 − 20) / 20; límite (11 + 10,5 − 20) / 20.
            ->and($r->json('indicadores'))->toEqual([
                'proyectos_activos' => 2, 'vaciado_m3' => 22.0, 'costo_soles' => 6520.0,
                'desperdicio_pct' => 10.0, 'limite_pct' => 7.5, 'supera_limite' => true,
            ])
            ->and($r->json('mensaje'))->toBeNull();
    });

    it('detalla cada proyecto con su costo valorizado y su desperdicio acumulado al cierre', function () {
        $e = escenarioDashboard();

        $proyectos = dashboard($e['gerente'], ['desde' => '2026-10', 'hasta' => '2026-10'])->json('proyectos');

        expect($proyectos)->toHaveCount(2)
            // G1 al precio de su factura y G2 al último facturado de A (310), no al de D (999).
            ->and($proyectos[0])->toEqual([
                'id' => $e['a']->id, 'codigo' => 'OBR-01', 'nombre' => 'Obra OBR-01',
                'guias' => 2, 'recibido_m3' => 12.0, 'vaciado_m3' => 12.0, 'costo_soles' => 3720.0,
                'desperdicio' => [
                    'sectores' => 1, 'presupuestado_m3' => 10.0, 'vaciado_m3' => 12.0, 'diferencia_m3' => 2.0,
                    'desperdicio_pct' => 20.0, 'limite_m3' => 11.0, 'supera_limite' => true, 'limite_pct' => 10.0,
                ],
            ])
            // Sin facturas, H1 se valoriza al precio presupuestado.
            ->and($proyectos[1])->toMatchArray(['codigo' => 'OBR-02', 'vaciado_m3' => 10.0, 'costo_soles' => 2800.0])
            ->and($proyectos[1]['desperdicio'])->toMatchArray(['desperdicio_pct' => 0.0, 'limite_pct' => 5.0, 'supera_limite' => false]);
    });

    it('por defecto muestra el mes en curso como parcial', function () {
        $e = escenarioDashboard();

        $r = dashboard($e['gerente'])->assertOk();

        expect($r->json('periodo'))->toMatchArray(['desde' => '2026-11', 'hasta' => '2026-11', 'parcial' => true])
            ->and($r->json('indicadores'))->toMatchArray(['vaciado_m3' => 4.0, 'costo_soles' => 1240.0])
            ->and($r->json('proyectos.1'))->toMatchArray(['guias' => 0, 'vaciado_m3' => 0.0, 'costo_soles' => 0.0]);
    });

    it('un rango de meses suma todos sus meses', function () {
        $e = escenarioDashboard();

        expect(dashboard($e['gerente'], ['desde' => '2026-09', 'hasta' => '2026-11'])->json('indicadores'))
            ->toMatchArray(['vaciado_m3' => 26.0, 'costo_soles' => 7760.0]);
    });

    it('sin proyectos activos asignados muestra el mensaje', function () {
        escenarioDashboard();

        $r = dashboard(conRol(Rol::Gerencia))->assertOk();

        expect($r->json('mensaje'))->toBe('No hay proyectos activos')
            ->and($r->json('proyectos'))->toBe([])
            ->and($r->json('indicadores'))->toEqual([
                'proyectos_activos' => 0, 'vaciado_m3' => 0.0, 'costo_soles' => 0.0,
                'desperdicio_pct' => null, 'limite_pct' => null, 'supera_limite' => false,
            ]);
    });

    it('no registra reportes generados', function () {
        $e = escenarioDashboard();

        dashboard($e['gerente'])->assertOk();

        expect(ReporteGenerado::count())->toBe(0);
    });
});

describe('alertas abiertas', function () {
    it('lista las pendientes de sus proyectos, de la más reciente a la más antigua', function () {
        $e = escenarioDashboard();
        $base = ['proyecto_id' => $e['a']->id, 'sector_id' => $e['s1']->id, 'exceso_m3' => 1, 'estado' => EstadoAlerta::Pendiente];
        $antigua = Alerta::create([...$base, 'fecha' => '2026-10-20 10:00:00']);
        $reciente = Alerta::create([...$base, 'fecha' => '2026-10-28 10:00:00']);
        Alerta::create([...$base, 'fecha' => '2026-10-29 10:00:00', 'estado' => EstadoAlerta::Revisada]);
        Alerta::create([...$base, 'proyecto_id' => $e['d']->id, 'sector_id' => $e['u1']->id, 'fecha' => '2026-10-30 10:00:00']);

        $alertas = dashboard($e['gerente'])->json('alertas');

        expect($alertas['pendientes'])->toBe(2)
            ->and(array_column($alertas['recientes'], 'id'))->toBe([$reciente->id, $antigua->id])
            ->and($alertas['recientes'][0]['proyecto']['codigo'])->toBe('OBR-01');
    });
});

describe('validación del rango', function () {
    it('rechaza un mes futuro, un rango invertido y un formato inválido', function (array $consulta, string $campo, string $error) {
        $e = escenarioDashboard();

        dashboard($e['gerente'], $consulta)->assertUnprocessable()->assertJsonValidationErrors([$campo => $error]);
    })->with([
        'mes futuro' => [['hasta' => '2026-12'], 'hasta', 'El mes no puede ser posterior al mes actual'],
        'rango invertido' => [['desde' => '2026-11', 'hasta' => '2026-10'], 'desde', 'El mes inicial no puede ser posterior al mes final'],
        'formato' => [['desde' => '2026-13'], 'desde', 'Seleccione un mes válido'],
    ]);
});

describe('permisos', function () {
    it('el administrador consulta todos los proyectos activos', function () {
        escenarioDashboard();

        $r = dashboard(conRol(Rol::Administrador))->assertOk();

        expect(array_column($r->json('proyectos'), 'codigo'))->toBe(['OBR-01', 'OBR-02', 'OBR-04']);
    });

    it('los demás roles reciben 403 aunque estén asignados', function (Rol $rol) {
        $e = escenarioDashboard();

        dashboard(asignadoA($e['a'], $rol))->assertForbidden();
    })->with([Rol::Almacenero, Rol::IngenieroProduccion, Rol::OficinaTecnica]);
});
