<?php

use App\Enums\Rol;
use App\Models\Factura;
use App\Models\GrupoPartida;
use App\Models\Proyecto;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'America/Lima'));
});

/**
 * Proyecto con inicio el 2026-09-01: P1 Columnas (210, 10 m³ a S/ 300) y P2 Vigas (280, 8 m³ a S/ 350),
 * con 10 % de desperdicio. S1 (P1, 10 m³) culminado el 10-28; S2 y S3 (P2, 4 m³ cada uno) abiertos.
 * F1 (10-15: 210 a 310, 280 a 360, Bombeo 150; subtotal 6 850) y F2 (11-05: 210 a 320; subtotal 3 200).
 */
function escenarioCorte(): array
{
    $proyecto = obra(['codigo' => 'OBR-01', 'nombre' => 'Edificio Mirador', 'fecha_inicio' => '2026-09-01']);
    $r210 = resistencia(210);
    $r280 = resistencia(280);
    $grupo = GrupoPartida::create(['nombre' => 'Estructuras', 'orden' => 1]);
    $p1 = partida($proyecto, datosPartida([
        'grupo_id' => $grupo->id, 'item' => '01.01', 'elemento' => 'Columnas', 'resistencia_id' => $r210->id,
        'metrado_m3' => 10, 'precio_unitario' => 300, 'factor_desperdicio' => 10,
    ]));
    $p2 = partida($proyecto, datosPartida([
        'grupo_id' => $grupo->id, 'item' => '01.02', 'elemento' => 'Vigas', 'resistencia_id' => $r280->id,
        'metrado_m3' => 8, 'precio_unitario' => 350, 'factor_desperdicio' => 10,
    ]));
    $nivel = $proyecto->niveles()->create(['nombre' => 'Piso 1', 'orden' => 1]);
    $s1 = Sector::create(['nivel_id' => $nivel->id, 'presupuesto_id' => $p1->id, 'nombre' => 'S1', 'volumen_presup_m3' => 10]);
    $s1->forceFill(['fecha_culminacion' => '2026-10-28'])->save();
    $s2 = Sector::create(['nivel_id' => $nivel->id, 'presupuesto_id' => $p2->id, 'nombre' => 'S2', 'volumen_presup_m3' => 4]);
    $s3 = Sector::create(['nivel_id' => $nivel->id, 'presupuesto_id' => $p2->id, 'nombre' => 'S3', 'volumen_presup_m3' => 4]);

    $f1 = facturaCorte($proyecto, 'F001-1', '2026-10-15', [[$r210, 310], [$r280, 360]], 20, 6850, ['Bombeo' => 150]);
    $f2 = facturaCorte($proyecto, 'F001-2', '2026-11-05', [[$r210, 320]], 10, 3200);

    guiaCorte($proyecto, 'G1', '2026-09-20', $r210, 5, $f1, [$s1->id => 5]);
    guiaCorte($proyecto, 'G2', '2026-09-25', $r210, 2, null, [$s1->id => 2]);
    guiaCorte($proyecto, 'G3', '2026-10-05', $r210, 4, $f1, [$s1->id => 4]);
    guiaCorte($proyecto, 'G4', '2026-10-10', $r280, 5, $f1, [$s2->id => 5]);
    // Facturada después del cierre de octubre: en octubre sigue pendiente, al precio de su factura.
    guiaCorte($proyecto, 'G5', '2026-10-20', $r210, 3, $f2, [$s3->id => 3]);
    guiaCorte($proyecto, 'G6', '2026-10-25', $r280, 2, null);
    guiaCorte($proyecto, 'G7', '2026-11-02', $r210, 1, null);

    // Otra obra en el mismo mes: no cuenta.
    $otra = obra(['codigo' => 'OBR-02']);
    guiaCorte($otra, 'X1', '2026-10-12', $r210, 9, facturaCorte($otra, 'F009-1', '2026-10-12', [[$r210, 999]], 10, 9990));

    return ['proyecto' => $proyecto, 'usuario' => asignadoA($proyecto, Rol::OficinaTecnica)];
}

function reporteCorte(User $usuario, Proyecto $proyecto, int $anio, int $mes, string $sufijo = '')
{
    return test()->actingAs($usuario)
        ->get("/api/v1/proyectos/{$proyecto->id}/reportes/corte-mes{$sufijo}?anio={$anio}&mes={$mes}", ['Accept' => 'application/json']);
}

describe('cálculo del corte', function () {
    it('suma lo recibido, vaciado y facturado del mes y lo pendiente acumulado al cierre', function () {
        $e = escenarioCorte();

        $r = reporteCorte($e['usuario'], $e['proyecto'], 2026, 10)->assertOk();

        expect($r->json('data.fecha_inicio'))->toBe('2026-10-01')
            ->and($r->json('data.fecha_cierre'))->toBe('2026-10-31')
            ->and($r->json('data.parcial'))->toBeFalse()
            ->and($r->json('data.resumen'))->toEqual([
                'guias_recibidas' => 4, 'recibido_m3' => 14.0, 'vaciado_m3' => 12.0,
                'facturas' => 1, 'facturado_m3' => 20.0, 'facturado_soles' => 6850.0,
                // G2 (setiembre, 2 × 310), G5 (3 × 320, su factura es de noviembre) y G6 (2 × 360).
                'guias_pendientes' => 3, 'pendiente_m3' => 7.0, 'pendiente_soles' => 2300.0,
            ]);
    });

    it('lista las guías del mes y las de meses anteriores aún pendientes, con su estado al cierre', function () {
        $e = escenarioCorte();

        $guias = collect(reporteCorte($e['usuario'], $e['proyecto'], 2026, 10)->json('data.guias'));

        expect($guias->map(fn ($g) => [$g['numero'], $g['estado'], $g['factura'], $g['precio_unitario'], $g['importe_soles'], $g['del_mes']])->all())
            ->toEqual([
                ['G2', 'pendiente', null, 310.0, 620.0, false],
                ['G3', 'facturada', 'F001-1', 310.0, 1240.0, true],
                ['G4', 'facturada', 'F001-1', 360.0, 1800.0, true],
                ['G5', 'pendiente', null, 320.0, 960.0, true],
                ['G6', 'pendiente', null, 360.0, 720.0, true],
            ])
            ->and($guias->firstWhere('numero', 'G5')['vaciado_m3'])->toEqual(3.0)
            ->and($guias->firstWhere('numero', 'G6')['proveedor'])->toBe('Concretera G6');
    });

    it('mide el desperdicio acumulado en los sectores culminados y en los abiertos que ya se pasaron', function () {
        $e = escenarioCorte();

        // S1 culminado (10 → 11) y S2 abierto pasado (4 → 5); S3 (4 → 3) aún no cuenta.
        expect(reporteCorte($e['usuario'], $e['proyecto'], 2026, 10)->json('data.desperdicio'))->toEqual([
            'sectores' => 2, 'presupuestado_m3' => 14.0, 'vaciado_m3' => 16.0, 'diferencia_m3' => 2.0,
            'desperdicio_pct' => 14.29, 'limite_m3' => 15.4, 'supera_limite' => true,
        ]);
    });

    it('en setiembre el sector aún no estaba culminado ni pasado: no hay desperdicio que medir', function () {
        $e = escenarioCorte();

        expect(reporteCorte($e['usuario'], $e['proyecto'], 2026, 9)->json('data.desperdicio'))->toMatchArray([
            'sectores' => 0, 'desperdicio_pct' => null, 'supera_limite' => false,
        ]);
    });

    it('el mes en curso es un corte parcial; lo facturado ya no figura como pendiente y el precio es el último facturado', function () {
        $e = escenarioCorte();

        $r = reporteCorte($e['usuario'], $e['proyecto'], 2026, 11)->assertOk();

        expect($r->json('data.parcial'))->toBeTrue()
            ->and(collect($r->json('data.guias'))->pluck('numero')->all())->toBe(['G2', 'G6', 'G7'])
            ->and($r->json('data.resumen'))->toMatchArray([
                'guias_recibidas' => 1, 'recibido_m3' => 1.0, 'vaciado_m3' => 0.0,
                'facturas' => 1, 'facturado_soles' => 3200.0,
                // G2 y G7 al último precio de 210 (320); G6 al de 280 (360).
                'guias_pendientes' => 3, 'pendiente_m3' => 5.0, 'pendiente_soles' => 1680.0,
            ]);
    });

    it('sin guías ni facturas en el mes muestra el mensaje y no registra el reporte', function () {
        $proyecto = obra(['fecha_inicio' => '2026-09-01']);

        reporteCorte(asignadoA($proyecto), $proyecto, 2026, 10)
            ->assertOk()
            ->assertExactJson(['data' => null, 'mensaje' => 'No hay movimientos en el mes seleccionado']);

        expect(DB::table('reportes_generados')->count())->toBe(0);
    });
});

describe('validación del mes', function () {
    it('rechaza un mes futuro, uno anterior al inicio y valores fuera de rango', function (int $anio, int $mes, string $error) {
        $e = escenarioCorte();

        reporteCorte($e['usuario'], $e['proyecto'], $anio, $mes)
            ->assertUnprocessable()
            ->assertJsonPath('errors.mes.0', $error);
    })->with([
        [2026, 12, 'El mes no puede ser posterior al mes actual'],
        [2026, 8, 'El mes no puede ser anterior al inicio del proyecto'],
        [2026, 13, 'Seleccione un mes válido'],
    ]);
});

describe('permisos', function () {
    it('lo generan Oficina técnica y Gerencia asignadas y el administrador', function (Rol $rol) {
        $e = escenarioCorte();
        $usuario = $rol === Rol::Administrador ? conRol($rol) : asignadoA($e['proyecto'], $rol);

        reporteCorte($usuario, $e['proyecto'], 2026, 10)->assertOk();
    })->with([Rol::OficinaTecnica, Rol::Gerencia, Rol::Administrador]);

    it('otros roles y usuarios no asignados reciben 403', function () {
        $e = escenarioCorte();

        reporteCorte(asignadoA($e['proyecto'], Rol::Almacenero), $e['proyecto'], 2026, 10)->assertForbidden();
        reporteCorte(conRol(Rol::OficinaTecnica), $e['proyecto'], 2026, 12)->assertForbidden();
    });
});

describe('registro y descargas', function () {
    it('registra cada generación con su mes y su formato', function () {
        $e = escenarioCorte();

        reporteCorte($e['usuario'], $e['proyecto'], 2026, 10)->assertOk();
        reporteCorte($e['usuario'], $e['proyecto'], 2026, 10, '/excel')->assertOk()->streamedContent();
        reporteCorte($e['usuario'], $e['proyecto'], 2026, 10, '/pdf')->assertOk();

        $registros = DB::table('reportes_generados')->orderBy('id')->get();
        expect($registros->pluck('formato')->all())->toBe(['pantalla', 'excel', 'pdf'])
            ->and($registros->pluck('tipo')->unique()->all())->toBe(['corte_mes'])
            ->and(json_decode($registros[0]->parametros, true))->toEqual(['anio' => 2026, 'mes' => 10]);
    });

    it('el Excel trae el resumen y una fila por guía', function () {
        $e = escenarioCorte();

        $r = reporteCorte($e['usuario'], $e['proyecto'], 2026, 10, '/excel')
            ->assertOk()
            ->assertDownload('reporte-corte-mes-OBR-01-202610.xlsx');

        $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($archivo, $r->streamedContent());
        $reader = new Reader;
        $reader->open($archivo);
        $filas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $fila) {
                $filas[] = $fila->toArray();
            }
        }
        $reader->close();
        unlink($archivo);

        $primeras = array_column($filas, 0);
        expect($primeras)->toContain('Pendiente de facturar al cierre', 'N.° guía', 'G2', 'G6')
            ->and(end($filas))->toEqual(['G6', 'Concretera G6', '25/10/2026', "f'c 280 kg/cm²", 2, 0, '—', 'Pendiente', 360, 720]);
    });

    it('el PDF se descarga con su nombre y sin movimientos responde 422 sin registrar', function () {
        $e = escenarioCorte();

        reporteCorte($e['usuario'], $e['proyecto'], 2026, 10, '/pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('reporte-corte-mes-OBR-01-202610.pdf');

        $vacio = obra(['fecha_inicio' => '2026-09-01']);
        reporteCorte(asignadoA($vacio), $vacio, 2026, 10, '/pdf')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No hay movimientos en el mes seleccionado');
        expect(DB::table('reportes_generados')->where('proyecto_id', $vacio->id)->count())->toBe(0);
    });
});
