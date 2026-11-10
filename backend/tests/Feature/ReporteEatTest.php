<?php

use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use App\Enums\Rol;
use App\Models\Asignacion;
use App\Models\Factura;
use App\Models\GrupoPartida;
use App\Models\GuiaRemision;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\Resistencia;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

const AVISO_SIN_SECTOR_EAT = 'Asigne un sector a estas guías: mientras no lo tengan, no suman al total.';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'America/Lima'));
});

/**
 * Proyecto con inicio el 2026-10-01: partidas P1 Columnas (210, 10 m³ a S/ 320), P2 Vigas (280, 8 m³ a
 * S/ 350) y P3 Losa aligerada (210, 5 m³ a S/ 260); facturas F1 (10-10: 210 a 310, 280 a 360, Bombeo 150)
 * y F2 (11-05: 210 a 320, Espera 80); otra obra con una factura de 210 a 999 que no debe contar.
 */
function escenarioEat(): array
{
    $proyecto = obra(['codigo' => 'OBR-01', 'nombre' => 'Edificio Mirador', 'fecha_inicio' => '2026-10-01']);
    $r210 = resistencia(210);
    $r280 = resistencia(280);
    // Losas se crea antes para que el orden salga de grupos_partida.orden y no del id.
    $losas = GrupoPartida::create(['nombre' => 'Losas', 'orden' => 2]);
    $estructuras = GrupoPartida::create(['nombre' => 'Estructuras', 'orden' => 1]);
    // P2 antes que P1: las filas se ordenan por ítem.
    $p2 = partidaEat($proyecto, $estructuras, '01.02', 'Vigas', $r280, 8, 350);
    $p1 = partidaEat($proyecto, $estructuras, '01.01', 'Columnas', $r210, 10, 320);
    $p3 = partidaEat($proyecto, $losas, '02.01', 'Losa aligerada', $r210, 5, 260);
    $nivel = $proyecto->niveles()->create(['nombre' => 'Piso 1', 'orden' => 1]);
    $s1 = sectorEat($nivel->id, $p1->id, 'S1', 10);
    $s2 = sectorEat($nivel->id, $p2->id, 'S2', 8);
    $s3 = sectorEat($nivel->id, $p3->id, 'S3', 5);

    $f1 = facturaEat($proyecto, '2026-10-10', [[$r210, 310], [$r280, 360]], ['Bombeo' => 150]);
    $f2 = facturaEat($proyecto, '2026-11-05', [[$r210, 320]], ['Espera' => 80]);
    facturaEat(obra(['codigo' => 'OBR-02']), '2026-10-15', [[$r210, 999]]);

    guiaEat($proyecto, '2026-10-05', $r210, 4, $f1, [$s1->id => 4]);
    guiaEat($proyecto, '2026-10-20', $r210, 3, null, [$s1->id => 3]);
    guiaEat($proyecto, '2026-10-25', $r280, 9, $f1, [$s2->id => 9]);
    guiaEat($proyecto, '2026-10-28', $r210, 2, null, [$s3->id => 2]);
    guiaEat($proyecto, '2026-11-03', $r210, 1.5, $f2, [$s3->id => 1.5]);
    guiaEat($proyecto, '2026-10-30', $r210, 1, null);
    guiaEat($proyecto, '2026-10-06', $r210, 1, null, [$s3->id => 1]);

    return [
        'proyecto' => $proyecto, 'usuario' => asignadoA($proyecto, Rol::OficinaTecnica),
        'p1' => $p1, 'p2' => $p2, 'p3' => $p3, 's1' => $s1, 's2' => $s2, 's3' => $s3,
        'estructuras' => $estructuras, 'losas' => $losas, 'f1' => $f1, 'f2' => $f2, 'r210' => $r210, 'r280' => $r280,
    ];
}

function partidaEat(Proyecto $proyecto, GrupoPartida $grupo, string $item, string $elemento, Resistencia $r, float $metrado, float $precio)
{
    return $proyecto->partidas()->create(datosPartida([
        'grupo_id' => $grupo->id, 'item' => $item, 'elemento' => $elemento, 'descripcion' => "CONCRETO - {$elemento}",
        'resistencia_id' => $r->id, 'metrado_m3' => $metrado, 'precio_unitario' => $precio,
    ]));
}

function sectorEat(int $nivelId, int $partidaId, string $nombre, float $volumen): Sector
{
    return Sector::create(['nivel_id' => $nivelId, 'presupuesto_id' => $partidaId, 'nombre' => $nombre, 'volumen_presup_m3' => $volumen]);
}

/** @param list<array{0: Resistencia, 1: float}> $lineas */
function facturaEat(Proyecto $proyecto, string $fecha, array $lineas, array $cargos = []): Factura
{
    static $numero = 0;
    $numero++;
    $factura = Factura::create([
        'proyecto_id' => $proyecto->id, 'proveedor_id' => Proveedor::factory()->create()->id,
        'numero' => sprintf('F001-%08d', $numero), 'fecha' => $fecha,
        'volumen_m3' => 10 * count($lineas), 'subtotal' => 0, 'igv' => 0, 'total' => 0,
        'archivo_ruta' => "facturas/{$proyecto->id}/f.pdf",
        'registrado_por' => conRol(Rol::OficinaTecnica)->id,
    ]);
    foreach ($lineas as [$resistencia, $precio]) {
        $factura->lineas()->create(['resistencia_id' => $resistencia->id, 'volumen_m3' => 10, 'precio_unitario' => $precio, 'importe' => 10 * $precio]);
    }
    foreach ($cargos as $descripcion => $importe) {
        $factura->cargos()->create(['descripcion' => $descripcion, 'importe' => $importe]);
    }

    return $factura;
}

/** @param array<int, float> $reparto volumen por id de sector */
function guiaEat(Proyecto $proyecto, string $fecha, Resistencia $r, float $volumen, ?Factura $factura, array $reparto = []): GuiaRemision
{
    static $numero = 0;
    $numero++;
    $usuario = conRol(Rol::Almacenero);
    $asignado = array_sum($reparto);
    $guia = GuiaRemision::create([
        'proyecto_id' => $proyecto->id, 'proveedor_id' => Proveedor::factory()->create()->id,
        'numero' => sprintf('T001-%08d', $numero), 'fecha' => $fecha, 'resistencia_id' => $r->id,
        'volumen_m3' => $volumen, 'foto_ruta' => "guias/{$proyecto->id}/foto.jpg",
        'estado_asignacion' => match (true) {
            $asignado <= 0 => EstadoAsignacion::SinSector,
            $asignado < $volumen => EstadoAsignacion::Parcial,
            default => EstadoAsignacion::Asignada,
        },
        'factura_id' => $factura?->id, 'origen' => OrigenGuia::Manual, 'registrado_por' => $usuario->id,
    ]);
    foreach ($reparto as $sectorId => $m3) {
        Asignacion::create(['guia_id' => $guia->id, 'sector_id' => $sectorId, 'volumen_m3' => $m3, 'registrado_por' => $usuario->id]);
    }

    return $guia;
}

function reporteEat(User $usuario, Proyecto $proyecto, ?string $corte)
{
    $consulta = $corte === null ? '' : "?fecha_corte={$corte}";

    return test()->actingAs($usuario)->getJson("/api/v1/proyectos/{$proyecto->id}/reportes/eat{$consulta}");
}

/** Los 8 montos en el orden de la tabla: presup., consumido, saldo y EAT, cada uno en m³ y S/. */
function montosEat(?float ...$m): array
{
    return array_combine(
        ['presupuesto_m3', 'presupuesto_soles', 'consumido_m3', 'consumido_soles', 'saldo_m3', 'saldo_soles', 'eat_m3', 'eat_soles'],
        $m,
    );
}

function filaEat(int $partidaId, string $item, string $etiqueta, array $montos, bool $sobrecosto): array
{
    return ['partida_id' => $partidaId, 'item' => $item, 'etiqueta' => $etiqueta, ...$montos, 'sobrecosto' => $sobrecosto];
}

function registrosEat(): array
{
    return DB::table('reportes_generados')
        ->orderBy('id')
        ->get(['usuario_id', 'proyecto_id', 'tipo', 'parametros', 'formato', 'created_at'])
        ->map(fn ($r) => [...(array) $r, 'parametros' => json_decode($r->parametros, true)])
        ->all();
}

describe('cálculo', function () {
    it('arma el reporte al corte con grupos, subtotales, filas aparte y total', function () {
        $e = escenarioEat();

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        // Último precio facturado al corte: 210 = 310 y 280 = 360, ambos de F1.
        expect($r->json())->toBe(['data' => [
            'proyecto' => ['id' => $e['proyecto']->id, 'codigo' => 'OBR-01', 'nombre' => 'Edificio Mirador'],
            'fecha_corte' => '2026-10-31',
            'grupos' => [
                [
                    'grupo_id' => $e['estructuras']->id, 'nombre' => 'Estructuras',
                    'filas' => [
                        // 10 × 320 = 3200; 4 × 310 + 3 × 310 = 2170; 3200 − 2170 = 1030; 2170 + 3 × 310 = 3100
                        filaEat($e['p1']->id, '01.01', "Columnas · f'c 210 kg/cm²", montosEat(10.0, 3200.0, 7.0, 2170.0, 3.0, 1030.0, 10.0, 3100.0), false),
                        // 8 × 350 = 2800; 9 × 360 = 3240; saldo máx(0, 8 − 9) = 0 y 2800 − 3240 = −440; EAT = consumido
                        filaEat($e['p2']->id, '01.02', "Vigas · f'c 280 kg/cm²", montosEat(8.0, 2800.0, 9.0, 3240.0, 0.0, -440.0, 9.0, 3240.0), true),
                    ],
                    // 3200 + 2800 = 6000; 2170 + 3240 = 5410; 1030 − 440 = 590; 3100 + 3240 = 6340 > 6000
                    'subtotal' => [...montosEat(18.0, 6000.0, 16.0, 5410.0, 3.0, 590.0, 19.0, 6340.0), 'sobrecosto' => true],
                ],
                [
                    'grupo_id' => $e['losas']->id, 'nombre' => 'Losas',
                    'filas' => [
                        // 5 × 260 = 1300; 2 × 310 + 1 × 310 = 930 (la guía del 11-03 queda fuera); 1300 − 930 = 370; 930 + 2 × 310 = 1550
                        filaEat($e['p3']->id, '02.01', "Losa aligerada · f'c 210 kg/cm²", montosEat(5.0, 1300.0, 3.0, 930.0, 2.0, 370.0, 5.0, 1550.0), true),
                    ],
                    'subtotal' => [...montosEat(5.0, 1300.0, 3.0, 930.0, 2.0, 370.0, 5.0, 1550.0), 'sobrecosto' => true],
                ],
            ],
            'filas_aparte' => [
                // 1 × 310 = 310
                ['tipo' => 'guias_sin_sector', 'concepto' => 'Guías sin sector', ...montosEat(0.0, 0.0, 1.0, 310.0, 0.0, -310.0, 1.0, 310.0), 'sobrecosto' => false, 'aviso' => AVISO_SIN_SECTOR_EAT],
                // Espera es de F2 (11-05): queda fuera.
                ['tipo' => 'cargo', 'concepto' => 'Bombeo', ...montosEat(0.0, 0.0, null, 150.0, null, -150.0, null, 150.0), 'sobrecosto' => false],
            ],
            // Las guías sin sector no suman; Bombeo sí.
            // 6000 + 1300 = 7300; 5410 + 930 + 150 = 6490; 590 + 370 − 150 = 810; 6340 + 1550 + 150 = 8040
            // m³: 16 + 3 = 19; 3 + 2 = 5; 19 + 5 = 24
            'total' => [...montosEat(23.0, 7300.0, 19.0, 6490.0, 5.0, 810.0, 24.0, 8040.0), 'sobrecosto' => true],
        ]]);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('valoriza el presupuesto con el metrado por el precio unitario de la partida', function () {
        $e = escenarioEat();

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        expect($r->json('data.grupos.0.filas.0.presupuesto_soles'))->toBe(3200.0); // 10 × 320
        expect($r->json('data.grupos.0.filas.1.presupuesto_soles'))->toBe(2800.0); // 8 × 350
        expect($r->json('data.grupos.1.filas.0.presupuesto_soles'))->toBe(1300.0); // 5 × 260
        expect($r->json('data.total.presupuesto_soles'))->toBe(7300.0); // 3200 + 2800 + 1300
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('una guía facturada toma el precio de su factura aunque la factura sea posterior al corte', function () {
        $e = escenarioEat();

        // Al 10-08 aún no hay facturas; la guía del 10-05 está en F1 (10-10): 4 × 310 = 1240.
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-08')->assertOk();
        expect($r->json('data.grupos.0.filas.0'))->toBe(
            // 10 × 320 = 3200; 3200 − 1240 = 1960; 1240 + 6 × 320 = 3160 (precio de la partida)
            filaEat($e['p1']->id, '01.01', "Columnas · f'c 210 kg/cm²", montosEat(10.0, 3200.0, 4.0, 1240.0, 6.0, 1960.0, 10.0, 3160.0), false),
        );
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('una guía cuya factura no tiene su resistencia toma el último precio facturado', function () {
        $e = escenarioEat();
        // F2 solo factura 210: esta guía de 280 toma el último precio facturado de 280 al corte, 360 de F1.
        guiaEat($e['proyecto'], '2026-10-29', $e['r280'], 1, $e['f2'], [$e['s2']->id => 1]);

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        // 9 × 360 + 1 × 360 = 3600; 2800 − 3600 = −800
        expect($r->json('data.grupos.0.filas.1'))->toBe(
            filaEat($e['p2']->id, '01.02', "Vigas · f'c 280 kg/cm²", montosEat(8.0, 2800.0, 10.0, 3600.0, 0.0, -800.0, 10.0, 3600.0), true),
        );
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('una guía sin factura toma el último precio facturado del proyecto al corte o, sin él, el de la partida', function () {
        $e = escenarioEat();

        // Al 10-31: la guía del 10-20 toma 310 de F1 (10-10), no el 999 de la otra obra (10-15).
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();
        expect($r->json('data.grupos.0.filas.0.consumido_soles'))->toBe(2170.0); // 4 × 310 + 3 × 310
        expect($r->json('data.grupos.0.filas.0.eat_soles'))->toBe(3100.0); // 2170 + 3 × 310
        expect($r->json('data.grupos.1.filas.0.consumido_soles'))->toBe(930.0); // 2 × 310 + 1 × 310

        // Al 10-08 no hay facturas: la guía del 10-06 y el saldo se valorizan con el precio de la partida.
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-08')->assertOk();
        expect($r->json('data.grupos'))->toBe([
            [
                'grupo_id' => $e['estructuras']->id, 'nombre' => 'Estructuras',
                'filas' => [
                    filaEat($e['p1']->id, '01.01', "Columnas · f'c 210 kg/cm²", montosEat(10.0, 3200.0, 4.0, 1240.0, 6.0, 1960.0, 10.0, 3160.0), false),
                    // 0 + 8 × 350 = 2800
                    filaEat($e['p2']->id, '01.02', "Vigas · f'c 280 kg/cm²", montosEat(8.0, 2800.0, 0.0, 0.0, 8.0, 2800.0, 8.0, 2800.0), false),
                ],
                // 1240 + 0 = 1240; 1960 + 2800 = 4760; 3160 + 2800 = 5960
                'subtotal' => [...montosEat(18.0, 6000.0, 4.0, 1240.0, 14.0, 4760.0, 18.0, 5960.0), 'sobrecosto' => false],
            ],
            [
                'grupo_id' => $e['losas']->id, 'nombre' => 'Losas',
                // 1 × 260 = 260; 1300 − 260 = 1040; 260 + 4 × 260 = 1300
                'filas' => [filaEat($e['p3']->id, '02.01', "Losa aligerada · f'c 210 kg/cm²", montosEat(5.0, 1300.0, 1.0, 260.0, 4.0, 1040.0, 5.0, 1300.0), false)],
                'subtotal' => [...montosEat(5.0, 1300.0, 1.0, 260.0, 4.0, 1040.0, 5.0, 1300.0), 'sobrecosto' => false],
            ],
        ]);
        expect($r->json('data.filas_aparte'))->toBe([]);
        // 1240 + 260 = 1500; 4760 + 1040 = 5800; 5960 + 1300 = 7260
        expect($r->json('data.total'))->toBe([...montosEat(23.0, 7300.0, 5.0, 1500.0, 18.0, 5800.0, 23.0, 7260.0), 'sobrecosto' => false]);
        expect(DB::table('reportes_generados')->count())->toBe(2);
    });

    it('con dos facturas de la misma fecha toma el precio de la registrada después', function () {
        $e = escenarioEat();
        facturaEat($e['proyecto'], '2026-10-10', [[$e['r210'], 315]]);

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        // 4 × 310 (su factura F1) + 3 × 315 = 1240 + 945 = 2185; EAT 2185 + 3 × 315 = 3130
        expect($r->json('data.grupos.0.filas.0'))->toBe(
            filaEat($e['p1']->id, '01.01', "Columnas · f'c 210 kg/cm²", montosEat(10.0, 3200.0, 7.0, 2185.0, 3.0, 1015.0, 10.0, 3130.0), false),
        );
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('valoriza las guías sin sector sin precio facturado con el promedio del presupuesto ponderado por metrado', function () {
        $e = escenarioEat();
        guiaEat($e['proyecto'], '2026-10-07', $e['r210'], 2, null);

        // Al 10-08 no hay facturas: promedio de 210 = (10 × 320 + 5 × 260) / 15 = 4500 / 15 = 300; 2 × 300 = 600
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-08')->assertOk();
        expect($r->json('data.filas_aparte'))->toBe([
            ['tipo' => 'guias_sin_sector', 'concepto' => 'Guías sin sector', ...montosEat(0.0, 0.0, 2.0, 600.0, 0.0, -600.0, 2.0, 600.0), 'sobrecosto' => false, 'aviso' => AVISO_SIN_SECTOR_EAT],
        ]);

        // Al 10-31: (1 + 2) × 310 = 930
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();
        expect($r->json('data.filas_aparte.0'))->toBe(
            ['tipo' => 'guias_sin_sector', 'concepto' => 'Guías sin sector', ...montosEat(0.0, 0.0, 3.0, 930.0, 0.0, -930.0, 3.0, 930.0), 'sobrecosto' => false, 'aviso' => AVISO_SIN_SECTOR_EAT],
        );
        expect(DB::table('reportes_generados')->count())->toBe(2);
    });

    it('una guía sin sector de una resistencia que no está en el presupuesto ni facturada vale 0', function () {
        $e = escenarioEat();
        guiaEat($e['proyecto'], '2026-10-07', resistencia(350), 1.5, null);

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-08')->assertOk();

        // 1.5 × 0 = 0
        expect($r->json('data.filas_aparte'))->toBe([
            ['tipo' => 'guias_sin_sector', 'concepto' => 'Guías sin sector', ...montosEat(0.0, 0.0, 1.5, 0.0, 0.0, 0.0, 1.5, 0.0), 'sobrecosto' => false, 'aviso' => AVISO_SIN_SECTOR_EAT],
        ]);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('redondea cada fila al céntimo con la mitad hacia arriba y suma las filas ya redondeadas', function () {
        $proyecto = obra(['fecha_inicio' => '2026-10-01']);
        $r210 = resistencia(210);
        $grupo = GrupoPartida::create(['nombre' => 'Estructuras', 'orden' => 1]);
        $a = partidaEat($proyecto, $grupo, '01.01', 'Columnas', $r210, 2, 100);
        $b = partidaEat($proyecto, $grupo, '01.02', 'Placas', $r210, 2, 100);
        $nivel = $proyecto->niveles()->create(['nombre' => 'Piso 1', 'orden' => 1]);
        $sa = sectorEat($nivel->id, $a->id, 'SA', 2);
        $sb = sectorEat($nivel->id, $b->id, 'SB', 2);
        $factura = facturaEat($proyecto, '2026-10-10', [[$r210, 310.5]]);
        guiaEat($proyecto, '2026-10-11', $r210, 1.05, $factura, [$sa->id => 1.05]);
        guiaEat($proyecto, '2026-10-12', $r210, 1.05, $factura, [$sb->id => 1.05]);

        $r = reporteEat(asignadoA($proyecto), $proyecto, '2026-10-31')->assertOk();

        // Consumido: 1.05 × 310.50 = 326.025 → 326.03. Saldo: 2 × 100 − 326.03 = −126.03.
        // EAT: 0.95 × 310.50 = 294.975 → 294.98; 326.03 + 294.98 = 621.01.
        $fila = montosEat(2.0, 200.0, 1.05, 326.03, 0.95, -126.03, 2.0, 621.01);
        expect($r->json('data.grupos.0.filas.0'))->toBe(filaEat($a->id, '01.01', "Columnas · f'c 210 kg/cm²", $fila, true));
        expect($r->json('data.grupos.0.filas.1'))->toBe(filaEat($b->id, '01.02', "Placas · f'c 210 kg/cm²", $fila, true));
        // Suma de filas redondeadas: 326.03 × 2 = 652.06 (no 652.05); 621.01 × 2 = 1242.02 (no 1242.00); −126.03 × 2 = −252.06
        $suma = montosEat(4.0, 400.0, 2.1, 652.06, 1.9, -252.06, 4.0, 1242.02);
        expect($r->json('data.grupos.0.subtotal'))->toBe([...$suma, 'sobrecosto' => true]);
        expect($r->json('data.total'))->toBe([...$suma, 'sobrecosto' => true]);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('la fecha de corte deja fuera las guías y los cargos posteriores', function () {
        $e = escenarioEat();

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();
        // La guía del 11-03 y el cargo Espera de F2 (11-05) no entran: 2 × 310 + 1 × 310 = 930.
        expect($r->json('data.grupos.1.filas.0.consumido_m3'))->toBe(3.0);
        expect($r->json('data.grupos.1.filas.0.consumido_soles'))->toBe(930.0);
        expect(array_column($r->json('data.filas_aparte'), 'concepto'))->toBe(['Guías sin sector', 'Bombeo']);

        // Al 11-09 entran, y el último precio de 210 pasa a 320 (F2): 2 × 320 + 1 × 320 + 1.5 × 320 = 1440.
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-11-09')->assertOk();
        expect($r->json('data.grupos.1.filas.0.consumido_m3'))->toBe(4.5);
        expect($r->json('data.grupos.1.filas.0.consumido_soles'))->toBe(1440.0);
        expect(array_column($r->json('data.filas_aparte'), 'concepto'))->toBe(['Guías sin sector', 'Bombeo', 'Espera']);
        expect(array_column($r->json('data.filas_aparte'), 'consumido_soles'))->toBe([320.0, 150.0, 80.0]);
        expect(DB::table('reportes_generados')->count())->toBe(2);
    });

    it('si el elemento superó lo presupuestado el EAT es el consumido', function () {
        $e = escenarioEat();

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        // Consumido 9 m³ > metrado 8: saldo 0 m³ y EAT = consumido 9 × 360 = 3240 > 2800
        expect($r->json('data.grupos.0.filas.1'))->toBe(
            filaEat($e['p2']->id, '01.02', "Vigas · f'c 280 kg/cm²", montosEat(8.0, 2800.0, 9.0, 3240.0, 0.0, -440.0, 9.0, 3240.0), true),
        );
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('muestra aparte las guías sin sector con su aviso, sin sumarlas al total, y los cargos de factura sumando', function () {
        $e = escenarioEat();

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        expect($r->json('data.filas_aparte'))->toBe([
            // Guía del 10-30 sin sector: 1 × 310 = 310
            ['tipo' => 'guias_sin_sector', 'concepto' => 'Guías sin sector', ...montosEat(0.0, 0.0, 1.0, 310.0, 0.0, -310.0, 1.0, 310.0), 'sobrecosto' => false, 'aviso' => AVISO_SIN_SECTOR_EAT],
            ['tipo' => 'cargo', 'concepto' => 'Bombeo', ...montosEat(0.0, 0.0, null, 150.0, null, -150.0, null, 150.0), 'sobrecosto' => false],
        ]);
        // Subtotales más Bombeo, sin los 310 de la guía sin sector: 5410 + 930 + 150 = 6490
        $subtotales = $r->json('data.grupos.0.subtotal.consumido_soles') + $r->json('data.grupos.1.subtotal.consumido_soles');
        expect($r->json('data.total.consumido_soles'))->toBe($subtotales + 150.0)->toBe(6490.0);
        // 6340 + 1550 + 150 = 8040; m³ 19 + 5 = 24
        expect($r->json('data.total.eat_soles'))->toBe(8040.0);
        expect($r->json('data.total.eat_m3'))->toBe(24.0);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('sin guías ni cargos hasta el corte responde que no hay información y no lo registra', function () {
        $e = escenarioEat();

        reporteEat($e['usuario'], $e['proyecto'], '2026-10-03')
            ->assertOk()
            ->assertExactJson(['data' => null, 'mensaje' => 'No hay información para la fecha seleccionada']);
        expect(DB::table('reportes_generados')->count())->toBe(0);
    });
});

describe('validación de la fecha de corte', function () {
    it('rechaza la fecha con el motivo en español y no registra nada', function (?string $corte, string $mensaje) {
        $e = escenarioEat();

        reporteEat($e['usuario'], $e['proyecto'], $corte)
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['fecha_corte' => [$mensaje]]);
        expect(DB::table('reportes_generados')->count())->toBe(0);
    })->with([
        'sin fecha' => [null, 'Ingrese la fecha de corte'],
        'vacía' => ['', 'Ingrese la fecha de corte'],
        'otro formato' => ['31/10/2026', 'Ingrese una fecha válida'],
        'fecha inexistente' => ['2026-02-30', 'Ingrese una fecha válida'],
        'futura' => ['2026-11-10', 'La fecha de corte no puede ser posterior a hoy'],
        'anterior al inicio del proyecto' => ['2026-09-30', 'La fecha de corte no puede ser anterior al inicio del proyecto'],
    ]);

    it('toma el hoy de Lima y no el de UTC', function () {
        $e = escenarioEat();
        // 03:00 UTC del 10 = 22:00 del 9 en Lima.
        $this->travelTo(Carbon::parse('2026-11-10 03:00:00', 'UTC'));

        reporteEat($e['usuario'], $e['proyecto'], '2026-11-10')
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['fecha_corte' => ['La fecha de corte no puede ser posterior a hoy']]);
        reporteEat($e['usuario'], $e['proyecto'], '2026-11-09')->assertOk()->assertJsonPath('data.fecha_corte', '2026-11-09');
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('acepta como corte el día de inicio del proyecto', function () {
        $e = escenarioEat();

        reporteEat($e['usuario'], $e['proyecto'], '2026-10-01')
            ->assertOk()
            ->assertExactJson(['data' => null, 'mensaje' => 'No hay información para la fecha seleccionada']);
        expect(DB::table('reportes_generados')->count())->toBe(0);
    });
});

describe('permisos', function () {
    it('lo generan Oficina técnica y Gerencia asignadas y el administrador sin asignación', function () {
        $e = escenarioEat();

        reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk()->assertJsonPath('data.total.eat_soles', 8040.0);
        reporteEat(asignadoA($e['proyecto'], Rol::Gerencia), $e['proyecto'], '2026-10-31')->assertOk()->assertJsonPath('data.total.eat_soles', 8040.0);
        reporteEat(conRol(Rol::Administrador), $e['proyecto'], '2026-10-31')->assertOk()->assertJsonPath('data.total.eat_soles', 8040.0);
        expect(DB::table('reportes_generados')->count())->toBe(3);
    });

    it('rechaza los roles fuera del reporte aunque estén asignados al proyecto', function (Rol $rol) {
        $e = escenarioEat();

        reporteEat(asignadoA($e['proyecto'], $rol), $e['proyecto'], '2026-10-31')
            ->assertForbidden()
            ->assertExactJson(['message' => 'No tiene permiso para realizar esta acción.', 'code' => 'sin_permiso']);
        expect(DB::table('reportes_generados')->count())->toBe(0);
    })->with([Rol::IngenieroProduccion, Rol::Almacenero]);

    it('rechaza un proyecto no asignado a Oficina técnica o Gerencia', function (Rol $rol) {
        $e = escenarioEat();

        reporteEat(conRol($rol), $e['proyecto'], '2026-10-31')
            ->assertForbidden()
            ->assertExactJson(['message' => 'No tiene acceso a este proyecto.', 'code' => 'sin_permiso']);
        expect(DB::table('reportes_generados')->count())->toBe(0);
    })->with([Rol::OficinaTecnica, Rol::Gerencia]);

    it('pide iniciar sesión', function () {
        $e = escenarioEat();

        $this->getJson("/api/v1/proyectos/{$e['proyecto']->id}/reportes/eat?fecha_corte=2026-10-31")->assertUnauthorized();
        expect(DB::table('reportes_generados')->count())->toBe(0);
    });
});

describe('registro de reportes generados', function () {
    it('registra cada generación con usuario, proyecto, tipo, fecha de corte y formato', function () {
        $e = escenarioEat();
        $gerencia = asignadoA($e['proyecto'], Rol::Gerencia);

        reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();
        reporteEat($gerencia, $e['proyecto'], '2026-10-08')->assertOk();

        $ahora = now()->format('Y-m-d H:i:s');
        expect(registrosEat())->toBe([
            ['usuario_id' => $e['usuario']->id, 'proyecto_id' => $e['proyecto']->id, 'tipo' => 'eat', 'parametros' => ['fecha_corte' => '2026-10-31'], 'formato' => 'pantalla', 'created_at' => $ahora],
            ['usuario_id' => $gerencia->id, 'proyecto_id' => $e['proyecto']->id, 'tipo' => 'eat', 'parametros' => ['fecha_corte' => '2026-10-08'], 'formato' => 'pantalla', 'created_at' => $ahora],
        ]);
    });
});

function exportarEat(User $usuario, Proyecto $proyecto, string $formato, ?string $corte)
{
    $consulta = $corte === null ? '' : "?fecha_corte={$corte}";

    return test()->actingAs($usuario)->getJson("/api/v1/proyectos/{$proyecto->id}/reportes/eat/{$formato}{$consulta}");
}

/** Las filas no vacías del Excel, con los números a 2 decimales para compararlos sin importar int o float. */
function filasExcelEat(string $contenido): array
{
    $archivo = tempnam(sys_get_temp_dir(), 'eat').'.xlsx';
    file_put_contents($archivo, $contenido);
    $reader = new Reader;
    $reader->open($archivo);
    $filas = [];
    foreach ($reader->getSheetIterator() as $hoja) {
        foreach ($hoja->getRowIterator() as $fila) {
            $valores = array_map(fn ($v) => is_int($v) || is_float($v) ? round((float) $v, 2) : $v, $fila->toArray());
            // El lector rellena cada fila hasta el ancho de la hoja con celdas vacías.
            while ($valores !== [] && in_array(end($valores), ['', null], true)) {
                array_pop($valores);
            }
            if ($valores !== []) {
                $filas[] = $valores;
            }
        }
    }
    $reader->close();
    unlink($archivo);

    return $filas;
}

describe('exportación', function () {
    it('descarga el Excel con el encabezado, las columnas de la pantalla y las filas y totales del corte', function () {
        $e = escenarioEat();

        $r = exportarEat($e['usuario'], $e['proyecto'], 'excel', '2026-10-31')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('reporte-eat-OBR-01-20261031.xlsx');

        // Los mismos montos que la respuesta JSON del corte 2026-10-31 (cuentas en la prueba de cálculo).
        expect(filasExcelEat($r->streamedContent()))->toBe([
            ['Reporte EAT'],
            ['Proyecto', 'OBR-01 Edificio Mirador'],
            ['Fecha de corte', '31/10/2026'],
            ['Montos sin IGV'],
            ['Elemento', 'Presup. m³', 'Presup. S/', 'Consumido m³', 'Consumido S/', 'Saldo m³', 'Saldo S/', 'EAT m³', 'EAT S/'],
            ['Estructuras'],
            ["01.01 Columnas · f'c 210 kg/cm²", 10.0, 3200.0, 7.0, 2170.0, 3.0, 1030.0, 10.0, 3100.0],
            ["01.02 Vigas · f'c 280 kg/cm²", 8.0, 2800.0, 9.0, 3240.0, 0.0, -440.0, 9.0, 3240.0],
            ['Subtotal Estructuras', 18.0, 6000.0, 16.0, 5410.0, 3.0, 590.0, 19.0, 6340.0],
            ['Losas'],
            ["02.01 Losa aligerada · f'c 210 kg/cm²", 5.0, 1300.0, 3.0, 930.0, 2.0, 370.0, 5.0, 1550.0],
            ['Subtotal Losas', 5.0, 1300.0, 3.0, 930.0, 2.0, 370.0, 5.0, 1550.0],
            ['Guías sin sector', 0.0, 0.0, 1.0, 310.0, 0.0, -310.0, 1.0, 310.0],
            [AVISO_SIN_SECTOR_EAT],
            ['Bombeo', 0.0, 0.0, '—', 150.0, '—', -150.0, '—', 150.0],
            ['Total', 23.0, 7300.0, 19.0, 6490.0, 5.0, 810.0, 24.0, 8040.0],
        ]);
        // 8040 > 7300: el EAT S/ del total va en rojo.
        expect(fuenteExcelEat($r->streamedContent(), 'Total', 'I'))->toBe(['negrita' => true, 'rojo' => true]);
    });

    it('descarga el PDF con su tipo y nombre de archivo', function () {
        $e = escenarioEat();
        $datos = null;
        View::composer('reportes.eat', function ($vista) use (&$datos) {
            $datos ??= $vista->getData();
        });

        $r = exportarEat($e['usuario'], $e['proyecto'], 'pdf', '2026-10-31')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('reporte-eat-OBR-01-20261031.pdf');

        expect(substr($r->getContent(), 0, 5))->toBe('%PDF-');
        // 8040 > 7300: el EAT S/ del total lleva la clase de sobrecosto.
        $total = collect(filasHtmlEat(view('reportes.eat', $datos)->render()))->last();
        expect($total['clase'])->toBe('total');
        expect(end($total['celdas']))->toBe(['8,040.00', 'monto sobrecosto']);
    });

    it('registra cada descarga con su formato', function () {
        $e = escenarioEat();

        exportarEat($e['usuario'], $e['proyecto'], 'excel', '2026-10-31')->assertOk()->streamedContent();
        exportarEat($e['usuario'], $e['proyecto'], 'pdf', '2026-10-08')->assertOk();

        $ahora = now()->format('Y-m-d H:i:s');
        expect(registrosEat())->toBe([
            ['usuario_id' => $e['usuario']->id, 'proyecto_id' => $e['proyecto']->id, 'tipo' => 'eat', 'parametros' => ['fecha_corte' => '2026-10-31'], 'formato' => 'excel', 'created_at' => $ahora],
            ['usuario_id' => $e['usuario']->id, 'proyecto_id' => $e['proyecto']->id, 'tipo' => 'eat', 'parametros' => ['fecha_corte' => '2026-10-08'], 'formato' => 'pdf', 'created_at' => $ahora],
        ]);
    });

    it('sin guías ni cargos hasta el corte responde 422 y no registra nada', function (string $formato) {
        $e = escenarioEat();

        exportarEat($e['usuario'], $e['proyecto'], $formato, '2026-10-03')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'No hay información para la fecha seleccionada']);
        expect(DB::table('reportes_generados')->count())->toBe(0);
    })->with(['excel', 'pdf']);

    it('valida la fecha de corte igual que la pantalla', function (string $formato) {
        $e = escenarioEat();

        exportarEat($e['usuario'], $e['proyecto'], $formato, '2026-11-10')->assertStatus(422)->assertJsonValidationErrors('fecha_corte');
        exportarEat($e['usuario'], $e['proyecto'], $formato, null)->assertStatus(422)->assertJsonValidationErrors('fecha_corte');
        expect(DB::table('reportes_generados')->count())->toBe(0);
    })->with(['excel', 'pdf']);

    it('rechaza un rol fuera del reporte y un proyecto no asignado', function (string $formato) {
        $e = escenarioEat();

        exportarEat(asignadoA($e['proyecto'], Rol::Almacenero), $e['proyecto'], $formato, '2026-10-31')
            ->assertForbidden()
            ->assertExactJson(['message' => 'No tiene permiso para realizar esta acción.', 'code' => 'sin_permiso']);
        exportarEat(conRol(Rol::OficinaTecnica), $e['proyecto'], $formato, '2026-10-31')
            ->assertForbidden()
            ->assertExactJson(['message' => 'No tiene acceso a este proyecto.', 'code' => 'sin_permiso']);
        expect(DB::table('reportes_generados')->count())->toBe(0);
    })->with(['excel', 'pdf']);

    it('pide iniciar sesión para exportar', function (string $formato) {
        $e = escenarioEat();

        $this->getJson("/api/v1/proyectos/{$e['proyecto']->id}/reportes/eat/{$formato}?fecha_corte=2026-10-31")->assertUnauthorized();
        expect(DB::table('reportes_generados')->count())->toBe(0);
    })->with(['excel', 'pdf']);
});

describe('permiso antes de validar', function () {
    it('a un usuario no asignado le responde 403 sin decirle si la fecha cabe en el proyecto', function (string $ruta) {
        $e = escenarioEat();
        $ajeno = conRol(Rol::Gerencia);

        // Antes del inicio, el día de inicio, otro formato y sin fecha: la misma respuesta.
        foreach (['?fecha_corte=2026-09-30', '?fecha_corte=2026-10-01', '?fecha_corte=31/10/2026', ''] as $consulta) {
            $this->actingAs($ajeno)->getJson("/api/v1/proyectos/{$e['proyecto']->id}/reportes/{$ruta}{$consulta}")
                ->assertForbidden()
                ->assertExactJson(['message' => 'No tiene acceso a este proyecto.', 'code' => 'sin_permiso']);
        }
        expect(DB::table('reportes_generados')->count())->toBe(0);
    })->with(['eat', 'eat/excel', 'eat/pdf']);

    it('al usuario asignado y al administrador les sigue validando la fecha', function (string $ruta) {
        $e = escenarioEat();

        foreach ([$e['usuario'], conRol(Rol::Administrador)] as $usuario) {
            $url = "/api/v1/proyectos/{$e['proyecto']->id}/reportes/{$ruta}";
            $this->actingAs($usuario)->getJson("{$url}?fecha_corte=2026-09-30")
                ->assertUnprocessable()
                ->assertJsonPath('errors', ['fecha_corte' => ['La fecha de corte no puede ser anterior al inicio del proyecto']]);
            $this->actingAs($usuario)->getJson("{$url}?fecha_corte=2026-10-31")->assertOk();
        }
        expect(DB::table('reportes_generados')->count())->toBe(2);
    })->with(['eat', 'eat/excel', 'eat/pdf']);
});

describe('precio de la propia factura', function () {
    it('una guía vinculada a una factura de otro proyecto no toma su precio', function () {
        $e = escenarioEat();
        $ajena = facturaEat(obra(['codigo' => 'OBR-03']), '2026-10-12', [[$e['r210'], 999]]);
        guiaEat($e['proyecto'], '2026-10-29', $e['r210'], 1, $ajena);
        guiaEat($e['proyecto'], '2026-10-29', $e['r210'], 1, $ajena, [$e['s3']->id => 1]);

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        // Toman el último precio facturado del proyecto (310 de F1), no el 999 de la otra obra.
        // Losa: 930 + 1 × 310 = 1240. Sin sector: (1 + 1) × 310 = 620.
        expect($r->json('data.grupos.1.filas.0.consumido_soles'))->toBe(1240.0);
        expect($r->json('data.filas_aparte.0'))->toBe(
            ['tipo' => 'guias_sin_sector', 'concepto' => 'Guías sin sector', ...montosEat(0.0, 0.0, 2.0, 620.0, 0.0, -620.0, 2.0, 620.0), 'sobrecosto' => false, 'aviso' => AVISO_SIN_SECTOR_EAT],
        );
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });
});

describe('límite de consultas', function () {
    it('limita las consultas por minuto de cada usuario con su propio límite', function (string $ruta) {
        config(['services.reportes.por_minuto' => 2, 'services.ocr.por_minuto' => 100]);
        $e = escenarioEat();
        $url = "/api/v1/proyectos/{$e['proyecto']->id}/reportes/{$ruta}?fecha_corte=2026-10-31";

        $this->actingAs($e['usuario'])->getJson($url)->assertOk();
        $this->actingAs($e['usuario'])->getJson($url)->assertOk();
        $this->actingAs($e['usuario'])->getJson($url)->assertStatus(429)->assertHeader('Retry-After');
        expect(DB::table('reportes_generados')->count())->toBe(2);

        // El límite es por usuario.
        $this->actingAs(asignadoA($e['proyecto'], Rol::Gerencia))->getJson($url)->assertOk();
        expect(DB::table('reportes_generados')->count())->toBe(3);
    })->with(['eat', 'eat/excel', 'eat/pdf']);

    it('gastar el cupo de las lecturas con OCR no bloquea el reporte', function (string $ruta) {
        config(['services.reportes.por_minuto' => 2, 'services.ocr.por_minuto' => 2]);
        $e = escenarioEat();

        // Sin archivo responde 422, pero la petición igual cuenta para el límite del OCR.
        $this->actingAs($e['usuario'])->postJson('/api/v1/facturas/lecturas')->assertStatus(422);
        $this->actingAs($e['usuario'])->postJson('/api/v1/facturas/lecturas')->assertStatus(422);
        $this->actingAs($e['usuario'])->postJson('/api/v1/facturas/lecturas')->assertStatus(429);

        $url = "/api/v1/proyectos/{$e['proyecto']->id}/reportes/{$ruta}?fecha_corte=2026-10-31";
        $this->actingAs($e['usuario'])->getJson($url)->assertOk();
        $this->actingAs($e['usuario'])->getJson($url)->assertOk();
        $this->actingAs($e['usuario'])->getJson($url)->assertStatus(429);
        expect(DB::table('reportes_generados')->count())->toBe(2);

        // Y el OCR sigue bloqueado: los dos límites no se comparten en ningún sentido.
        $this->actingAs($e['usuario'])->postJson('/api/v1/facturas/lecturas')->assertStatus(429);
    })->with(['eat', 'eat/excel', 'eat/pdf']);
});

/** El XML de la primera hoja del Excel, para ver qué celdas quedaron como fórmula. */
function hojaExcelEat(string $contenido): string
{
    $archivo = tempnam(sys_get_temp_dir(), 'eat');
    file_put_contents($archivo, $contenido);
    $zip = new ZipArchive;
    $zip->open($archivo);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($archivo);

    return $xml;
}

describe('textos del Excel', function () {
    it('escribe como texto los nombres que empiezan con =, +, - o @ y deja los montos como números', function () {
        $e = escenarioEat();
        $e['proyecto']->update(['codigo' => '=HOY()']);
        $e['estructuras']->update(['nombre' => '=1+1']);
        $e['losas']->update(['nombre' => '@SUMA(A1)']);
        $e['f1']->cargos()->update(['descripcion' => '+HIPERVINCULO("http://x")']);
        $e['f1']->cargos()->create(['descripcion' => '-2+3', 'importe' => 10]);

        $contenido = exportarEat($e['usuario'], $e['proyecto'], 'excel', '2026-10-31')->assertOk()->streamedContent();

        expect(hojaExcelEat($contenido))->not->toContain('<f>');
        $filas = collect(filasExcelEat($contenido))->keyBy(0);
        expect($filas['Proyecto'])->toBe(['Proyecto', "'=HOY() Edificio Mirador"]);
        expect($filas["'=1+1"])->toBe(["'=1+1"]);
        expect($filas["'@SUMA(A1)"])->toBe(["'@SUMA(A1)"]);
        expect($filas['Subtotal =1+1'])->toBe(['Subtotal =1+1', 18.0, 6000.0, 16.0, 5410.0, 3.0, 590.0, 19.0, 6340.0]);
        expect($filas["01.02 Vigas · f'c 280 kg/cm²"])->toBe(["01.02 Vigas · f'c 280 kg/cm²", 8.0, 2800.0, 9.0, 3240.0, 0.0, -440.0, 9.0, 3240.0]);
        expect($filas["'+HIPERVINCULO(\"http://x\")"])->toBe(["'+HIPERVINCULO(\"http://x\")", 0.0, 0.0, '—', 150.0, '—', -150.0, '—', 150.0]);
        expect($filas["'-2+3"])->toBe(["'-2+3", 0.0, 0.0, '—', 10.0, '—', -10.0, '—', 10.0]);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });
});

describe('nombre del archivo y registro de la descarga', function () {
    it('descarga con un nombre de archivo seguro aunque el código tenga barras, y la registra una vez', function (string $formato, string $codigo, string $archivo) {
        $e = escenarioEat();
        $e['proyecto']->update(['codigo' => $codigo]);

        $r = exportarEat($e['usuario'], $e['proyecto'], $formato, '2026-10-31')->assertOk()->assertDownload($archivo);

        $contenido = $formato === 'excel' ? $r->streamedContent() : $r->getContent();
        expect(substr($contenido, 0, $formato === 'excel' ? 2 : 5))->toBe($formato === 'excel' ? 'PK' : '%PDF-');
        expect(registrosEat())->toBe([
            ['usuario_id' => $e['usuario']->id, 'proyecto_id' => $e['proyecto']->id, 'tipo' => 'eat', 'parametros' => ['fecha_corte' => '2026-10-31'], 'formato' => $formato, 'created_at' => now()->format('Y-m-d H:i:s')],
        ]);
    })->with([
        'Excel con /' => ['excel', 'OB-2026/02', 'reporte-eat-OB-2026-02-20261031.xlsx'],
        'PDF con /' => ['pdf', 'OB-2026/02', 'reporte-eat-OB-2026-02-20261031.pdf'],
        'Excel con \\' => ['excel', 'OB\\01', 'reporte-eat-OB-01-20261031.xlsx'],
        'PDF con \\' => ['pdf', 'OB\\01', 'reporte-eat-OB-01-20261031.pdf'],
        'Excel con tildes y signos' => ['excel', 'PEÑA "Sur";01', 'reporte-eat-PENA-Sur-01-20261031.xlsx'],
    ]);

    it('si el archivo no se pudo generar responde con error y no registra la descarga', function (string $formato, string $generador) {
        $e = escenarioEat();
        $this->app->bind($generador, fn () => throw new RuntimeException('Falla al generar el archivo'));

        exportarEat($e['usuario'], $e['proyecto'], $formato, '2026-10-31')->assertStatus(500);

        expect(DB::table('reportes_generados')->count())->toBe(0);
    })->with([
        'Excel' => ['excel', Writer::class],
        'PDF' => ['pdf', 'dompdf.wrapper'],
    ]);
});

describe('opciones del PDF', function () {
    it('genera el PDF sin JavaScript y sin leer archivos fuera de las vistas', function () {
        $e = escenarioEat();
        $usado = null;
        $this->app->afterResolving('dompdf.wrapper', function ($pdf) use (&$usado) {
            $usado = $pdf;
        });

        $r = exportarEat($e['usuario'], $e['proyecto'], 'pdf', '2026-10-31')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('reporte-eat-OBR-01-20261031.pdf');

        expect(substr($r->getContent(), 0, 5))->toBe('%PDF-');
        // Con el subconjunto de fuentes el PDF de una página pesa unos 25 KB; sin él, ~880 KB.
        expect(strlen($r->getContent()))->toBeLessThan(200_000);
        $opciones = $usado->getDomPDF()->getOptions();
        expect($opciones->getIsJavascriptEnabled())->toBeFalse();
        expect($opciones->getChroot())->toBe([resource_path('views')]);
        expect($opciones->validateLocalUri('file://'.base_path('composer.json'))[0])->toBeFalse();
        expect($opciones->validateLocalUri('file://'.resource_path('views/reportes/eat.blade.php'))[0])->toBeTrue();
        // El resto de la configuración del paquete se conserva.
        expect($opciones->getIsFontSubsettingEnabled())->toBeTrue();
        expect($opciones->getFontDir())->toBe(storage_path('fonts'));
        expect($opciones->getIsRemoteEnabled())->toBeFalse();
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });
});

/**
 * Cuatro partidas sin consumo (605 m³ y S/ 229 950), ninguna factura y una guía f'c 210 de 6 m³ sin sector:
 * el total tiene que quedar en lo presupuestado.
 */
function escenarioSoloGuiaSinSector(): array
{
    $proyecto = obra(['codigo' => 'SC-01', 'nombre' => 'Obra sin consumo', 'fecha_inicio' => '2026-09-04']);
    $r210 = resistencia(210);
    $r280 = resistencia(280);
    $muros = GrupoPartida::create(['nombre' => 'Muros anclados', 'orden' => 1]);
    $cimentacion = GrupoPartida::create(['nombre' => 'Cimentación', 'orden' => 2]);
    $verticales = GrupoPartida::create(['nombre' => 'Verticales sótanos', 'orden' => 3]);
    partidaEat($proyecto, $muros, '01.01.01', 'Placa', $r280, 210, 385);
    partidaEat($proyecto, $cimentacion, '02.01.01', 'Zapata', $r210, 180, 375);
    partidaEat($proyecto, $cimentacion, '02.01.02', 'Cimiento corrido', $r210, 95, 360);
    partidaEat($proyecto, $verticales, '03.01.01', 'Columna', $r280, 120, 395);
    guiaEat($proyecto, '2026-06-26', $r210, 6, null);

    return ['proyecto' => $proyecto, 'usuario' => asignadoA($proyecto, Rol::OficinaTecnica)];
}

/** Negrita y rojo de la celda en la columna dada, en la fila cuya primera celda dice $texto. */
function fuenteExcelEat(string $contenido, string $texto, string $columna): array
{
    $archivo = tempnam(sys_get_temp_dir(), 'eat');
    file_put_contents($archivo, $contenido);
    $zip = new ZipArchive;
    $zip->open($archivo);
    $hoja = new DOMDocument;
    $hoja->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
    $estilos = new DOMDocument;
    $estilos->loadXML($zip->getFromName('xl/styles.xml'));
    $zip->close();
    unlink($archivo);

    $xh = new DOMXPath($hoja);
    $xh->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $fila = $xh->query("//m:row[m:c[1]//m:t[. = '{$texto}']]")->item(0);
    $celda = $xh->query("m:c[@r = '{$columna}{$fila->getAttribute('r')}']", $fila)->item(0);

    $xe = new DOMXPath($estilos);
    $xe->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $xf = $xe->query('//m:cellXfs/m:xf')->item((int) $celda->getAttribute('s'));
    $fuente = $xe->query('//m:fonts/m:font')->item((int) $xf->getAttribute('fontId'));
    $color = $xe->query('m:color', $fuente)->item(0)?->getAttribute('rgb') ?? '';

    return ['negrita' => $xe->query('m:b', $fuente)->length > 0, 'rojo' => str_ends_with(strtoupper($color), 'FF0000')];
}

/** Las filas de la tabla del HTML del PDF: la clase de cada fila y, por celda, su texto y su clase. */
function filasHtmlEat(string $html): array
{
    $documento = new DOMDocument;
    $documento->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR);

    $filas = [];
    foreach ((new DOMXPath($documento))->query('//tbody/tr') as $tr) {
        $celdas = [];
        foreach ($tr->getElementsByTagName('td') as $td) {
            $celdas[] = [trim($td->textContent), $td->getAttribute('class')];
        }
        $filas[] = ['clase' => $tr->getAttribute('class'), 'celdas' => $celdas];
    }

    return $filas;
}

describe('guías sin sector en una obra sin consumo', function () {
    it('el total queda en lo presupuestado y no marca sobrecosto; la guía sin sector sigue aparte con su aviso', function () {
        $e = escenarioSoloGuiaSinSector();

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-11-09')->assertOk();

        // Promedio presupuestado de 210: (180 × 375 + 95 × 360) / 275 = 369.8181…; 6 × 369.8181… = 2218.909 → 2218.91
        expect($r->json('data.filas_aparte'))->toBe([
            ['tipo' => 'guias_sin_sector', 'concepto' => 'Guías sin sector', ...montosEat(0.0, 0.0, 6.0, 2218.91, 0.0, -2218.91, 6.0, 2218.91), 'sobrecosto' => false, 'aviso' => AVISO_SIN_SECTOR_EAT],
        ]);
        // Sin consumo asignado, el EAT es el presupuesto: 605 m³ y S/ 229 950, no 611 m³ y S/ 232 168,91.
        expect($r->json('data.total'))->toBe([...montosEat(605.0, 229950.0, 0.0, 0.0, 605.0, 229950.0, 605.0, 229950.0), 'sobrecosto' => false]);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('en el Excel el total no suma la guía sin sector y el aviso va en la fila de abajo, sin rojo ni negrita', function () {
        $e = escenarioSoloGuiaSinSector();

        $contenido = exportarEat($e['usuario'], $e['proyecto'], 'excel', '2026-11-09')->assertOk()->streamedContent();

        expect(array_slice(filasExcelEat($contenido), -3))->toBe([
            ['Guías sin sector', 0.0, 0.0, 6.0, 2218.91, 0.0, -2218.91, 6.0, 2218.91],
            [AVISO_SIN_SECTOR_EAT],
            ['Total', 605.0, 229950.0, 0.0, 0.0, 605.0, 229950.0, 605.0, 229950.0],
        ]);
        expect(fuenteExcelEat($contenido, 'Total', 'I'))->toBe(['negrita' => true, 'rojo' => false]);
        expect(fuenteExcelEat($contenido, AVISO_SIN_SECTOR_EAT, 'A'))->toBe(['negrita' => false, 'rojo' => false]);
    });

    it('en el PDF el total no marca sobrecosto y el aviso va debajo de la fila sin sector', function () {
        $e = escenarioSoloGuiaSinSector();
        $datos = null;
        View::composer('reportes.eat', function ($vista) use (&$datos) {
            $datos ??= $vista->getData();
        });

        $r = exportarEat($e['usuario'], $e['proyecto'], 'pdf', '2026-11-09')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($r->getContent(), 0, 5))->toBe('%PDF-');

        // El mismo HTML con el que se generó el PDF.
        $filas = filasHtmlEat(view('reportes.eat', $datos)->render());
        expect(array_slice($filas, -3))->toBe([
            ['clase' => 'aparte', 'celdas' => [
                ['Guías sin sector', ''], ['0.00', 'monto'], ['0.00', 'monto'], ['6.00', 'monto'], ['2,218.91', 'monto'],
                ['0.00', 'monto'], ['-2,218.91', 'monto'], ['6.00', 'monto'], ['2,218.91', 'monto'],
            ]],
            ['clase' => 'aviso', 'celdas' => [[AVISO_SIN_SECTOR_EAT, '']]],
            ['clase' => 'total', 'celdas' => [
                ['Total', ''], ['605.00', 'monto'], ['229,950.00', 'monto'], ['0.00', 'monto'], ['0.00', 'monto'],
                ['605.00', 'monto'], ['229,950.00', 'monto'], ['605.00', 'monto'], ['229,950.00', 'monto'],
            ]],
        ]);
    });
});
