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
const AVISO_CARGOS_EAT = 'Los cargos que no son concreto incluyen solo lo facturado hasta la fecha de corte.';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'America/Lima'));
});

/**
 * Proyecto con inicio el 2026-10-01: partidas P1 Columnas (210, 10 m³ a S/ 320), P2 Vigas (280, 8 m³ a
 * S/ 350) y P3 Losa aligerada (210, 5 m³ a S/ 260), todas con 10 % de desperdicio; un sector por
 * partida, y S2 (Vigas) culminado el 10-26. Facturas F1 (10-10: 210 a 310, 280 a 360, Bombeo 150) y F2
 * (11-05: 210 a 320, Espera 80); otra obra con una factura de 210 a 999 que no debe contar.
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
    $s2 = sectorEat($nivel->id, $p2->id, 'S2', 8, '2026-10-26');
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

/** Con 10 % de desperdicio por defecto: el presupuesto meta es el metrado × 1.1. */
function partidaEat(Proyecto $proyecto, GrupoPartida $grupo, string $item, string $elemento, Resistencia $r, float $metrado, float $precio, float $desperdicio = 10)
{
    return $proyecto->partidas()->create(datosPartida([
        'grupo_id' => $grupo->id, 'item' => $item, 'elemento' => $elemento, 'descripcion' => "CONCRETO - {$elemento}",
        'resistencia_id' => $r->id, 'metrado_m3' => $metrado, 'precio_unitario' => $precio, 'factor_desperdicio' => $desperdicio,
    ]));
}

function sectorEat(int $nivelId, int $partidaId, string $nombre, float $volumen, ?string $culminacion = null): Sector
{
    $sector = Sector::create(['nivel_id' => $nivelId, 'presupuesto_id' => $partidaId, 'nombre' => $nombre, 'volumen_presup_m3' => $volumen]);
    if ($culminacion !== null) {
        $sector->forceFill(['fecha_culminacion' => $culminacion])->save();
    }

    return $sector;
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

/**
 * Los 12 montos en el orden de ReporteEatQuery::MONTOS: presup. m³ y S/, consumido m³ y S/, saldo S/,
 * real culminado m³ y S/, proyectado m³ y S/, EAT m³ y S/, variación S/.
 */
function montosEat(?float ...$m): array
{
    return array_combine(
        ['presupuesto_m3', 'presupuesto_soles', 'consumido_m3', 'consumido_soles', 'saldo_soles', 'real_m3', 'real_soles',
            'proyectado_m3', 'proyectado_soles', 'eat_m3', 'eat_soles', 'variacion_soles'],
        $m,
    );
}

/** La fila de las guías sin sector: solo lo recibido, sin EAT. */
function sinSectorEat(float $m3, float $soles): array
{
    return [
        'tipo' => 'guias_sin_sector', 'concepto' => 'Guías sin sector',
        ...montosEat(0.0, 0.0, $m3, $soles, null, null, null, null, null, null, null, null),
        'sobrecosto' => false, 'aviso' => AVISO_SIN_SECTOR_EAT,
    ];
}

/** Un cargo de factura: lo facturado es todo su EAT. */
function cargoEat(string $concepto, float $importe): array
{
    return [
        'tipo' => 'cargo', 'concepto' => $concepto,
        ...montosEat(0.0, 0.0, null, $importe, -$importe, null, $importe, null, 0.0, null, $importe, -$importe),
        'sobrecosto' => false,
    ];
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

        // Desperdicio 10 %. Último precio facturado al corte: 210 = 310 y 280 = 360, ambos de F1.
        expect($r->json())->toBe(['data' => [
            'proyecto' => ['id' => $e['proyecto']->id, 'codigo' => 'OBR-01', 'nombre' => 'Edificio Mirador'],
            'fecha_corte' => '2026-10-31',
            'grupos' => [
                [
                    'grupo_id' => $e['estructuras']->id, 'nombre' => 'Estructuras',
                    'filas' => [
                        // Presup. 10 × 1.1 = 11 m³ × 320 = 3520. Consumido 4 × 310 + 3 × 310 = 2170; saldo 1350.
                        // S1 abierto: nada real; proyecta 10 × 1.1 = 11 m³ × 310 = 3410. Variación 3520 − 3410 = 110.
                        filaEat($e['p1']->id, '01.01', "Columnas · f'c 210 kg/cm²", montosEat(11.0, 3520.0, 7.0, 2170.0, 1350.0, 0.0, 0.0, 11.0, 3410.0, 11.0, 3410.0, 110.0), false),
                        // Presup. 8 × 1.1 = 8.8 m³ × 350 = 3080. S2 culminado el 10-26: real 9 × 360 = 3240,
                        // nada por proyectar. Variación 3080 − 3240 = −160.
                        filaEat($e['p2']->id, '01.02', "Vigas · f'c 280 kg/cm²", montosEat(8.8, 3080.0, 9.0, 3240.0, -160.0, 9.0, 3240.0, 0.0, 0.0, 9.0, 3240.0, -160.0), true),
                    ],
                    'subtotal' => [...montosEat(19.8, 6600.0, 16.0, 5410.0, 1190.0, 9.0, 3240.0, 11.0, 3410.0, 20.0, 6650.0, -50.0), 'sobrecosto' => true],
                ],
                [
                    'grupo_id' => $e['losas']->id, 'nombre' => 'Losas',
                    'filas' => [
                        // Presup. 5 × 1.1 = 5.5 m³ × 260 = 1430. Consumido 2 × 310 + 1 × 310 = 930 (la guía del
                        // 11-03 queda fuera). S3 abierto: proyecta 5.5 × 310 = 1705. Variación 1430 − 1705 = −275.
                        filaEat($e['p3']->id, '02.01', "Losa aligerada · f'c 210 kg/cm²", montosEat(5.5, 1430.0, 3.0, 930.0, 500.0, 0.0, 0.0, 5.5, 1705.0, 5.5, 1705.0, -275.0), true),
                    ],
                    'subtotal' => [...montosEat(5.5, 1430.0, 3.0, 930.0, 500.0, 0.0, 0.0, 5.5, 1705.0, 5.5, 1705.0, -275.0), 'sobrecosto' => true],
                ],
            ],
            'filas_aparte' => [
                // 1 × 310 = 310, sin EAT.
                sinSectorEat(1.0, 310.0),
                // Espera es de F2 (11-05): queda fuera.
                cargoEat('Bombeo', 150.0),
            ],
            // Las guías sin sector no suman; Bombeo sí. Consumido 5410 + 930 + 150 = 6490; real 3240 + 150 = 3390;
            // proyectado 3410 + 1705 = 5115; EAT 3390 + 5115 = 8505; variación 8030 − 8505 = −475.
            'total' => [...montosEat(25.3, 8030.0, 19.0, 6490.0, 1540.0, 9.0, 3390.0, 16.5, 5115.0, 25.5, 8505.0, -475.0), 'sobrecosto' => true],
        ]]);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('valoriza el presupuesto meta con el desperdicio, igual que el total de la partida del CU04', function () {
        $e = escenarioEat();

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        expect($r->json('data.grupos.0.filas.0.presupuesto_soles'))->toBe(3520.0); // 10 × 1.10 × 320
        expect($r->json('data.grupos.0.filas.1.presupuesto_soles'))->toBe(3080.0); // 8 × 1.10 × 350
        expect($r->json('data.grupos.1.filas.0.presupuesto_soles'))->toBe(1430.0); // 5 × 1.10 × 260
        expect($r->json('data.total.presupuesto_soles'))->toBe(8030.0);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('sin sectores culminados ni precios facturados el EAT es el presupuesto meta', function () {
        $e = escenarioEat();

        // Al 10-08 no hay facturas y S2 aún no está culminado (10-26).
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-08')->assertOk();

        expect($r->json('data.grupos'))->toBe([
            [
                'grupo_id' => $e['estructuras']->id, 'nombre' => 'Estructuras',
                'filas' => [
                    // La guía del 10-05 toma el precio de su factura F1 (10-10): 4 × 310 = 1240. Lo que falta, al
                    // precio de la partida: 11 × 320 = 3520.
                    filaEat($e['p1']->id, '01.01', "Columnas · f'c 210 kg/cm²", montosEat(11.0, 3520.0, 4.0, 1240.0, 2280.0, 0.0, 0.0, 11.0, 3520.0, 11.0, 3520.0, 0.0), false),
                    filaEat($e['p2']->id, '01.02', "Vigas · f'c 280 kg/cm²", montosEat(8.8, 3080.0, 0.0, 0.0, 3080.0, 0.0, 0.0, 8.8, 3080.0, 8.8, 3080.0, 0.0), false),
                ],
                'subtotal' => [...montosEat(19.8, 6600.0, 4.0, 1240.0, 5360.0, 0.0, 0.0, 19.8, 6600.0, 19.8, 6600.0, 0.0), 'sobrecosto' => false],
            ],
            [
                'grupo_id' => $e['losas']->id, 'nombre' => 'Losas',
                // La guía del 10-06 sin factura: precio de la partida, 1 × 260 = 260.
                'filas' => [filaEat($e['p3']->id, '02.01', "Losa aligerada · f'c 210 kg/cm²", montosEat(5.5, 1430.0, 1.0, 260.0, 1170.0, 0.0, 0.0, 5.5, 1430.0, 5.5, 1430.0, 0.0), false)],
                'subtotal' => [...montosEat(5.5, 1430.0, 1.0, 260.0, 1170.0, 0.0, 0.0, 5.5, 1430.0, 5.5, 1430.0, 0.0), 'sobrecosto' => false],
            ],
        ]);
        expect($r->json('data.filas_aparte'))->toBe([]);
        expect($r->json('data.total'))->toBe([...montosEat(25.3, 8030.0, 5.0, 1500.0, 6530.0, 0.0, 0.0, 25.3, 8030.0, 25.3, 8030.0, 0.0), 'sobrecosto' => false]);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('un sector culminado después del corte cuenta como abierto, y lo que ya pasó de su límite se proyecta', function () {
        $e = escenarioEat();

        // Al 10-25 S2 sigue abierto (se culmina el 10-26) con 9 m³, sobre su límite de 8 × 1.1 = 8.8.
        // Proyecta 8.8 + 0.2 de exceso = 9 m³ × 360 = 3240: el EAT no queda debajo de lo ya vaciado.
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-25')->assertOk();
        expect($r->json('data.grupos.0.filas.1'))->toBe(
            filaEat($e['p2']->id, '01.02', "Vigas · f'c 280 kg/cm²", montosEat(8.8, 3080.0, 9.0, 3240.0, -160.0, 0.0, 0.0, 9.0, 3240.0, 9.0, 3240.0, -160.0), true),
        );

        // Al 10-26 ya está culminado: los mismos 9 m³ pasan a costo real y no queda nada por proyectar.
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-26')->assertOk();
        expect($r->json('data.grupos.0.filas.1'))->toBe(
            filaEat($e['p2']->id, '01.02', "Vigas · f'c 280 kg/cm²", montosEat(8.8, 3080.0, 9.0, 3240.0, -160.0, 9.0, 3240.0, 0.0, 0.0, 9.0, 3240.0, -160.0), true),
        );
        expect(DB::table('reportes_generados')->count())->toBe(2);
    });

    it('un sector culminado con menos concreto que su presupuesto da ahorro', function () {
        $e = escenarioEat();
        $e['s1']->forceFill(['fecha_culminacion' => '2026-10-30'])->save();

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        // S1 (10 m³ netos) culminó con 7 m³: real 2170 y nada por proyectar. Variación 3520 − 2170 = 1350.
        expect($r->json('data.grupos.0.filas.0'))->toBe(
            filaEat($e['p1']->id, '01.01', "Columnas · f'c 210 kg/cm²", montosEat(11.0, 3520.0, 7.0, 2170.0, 1350.0, 7.0, 2170.0, 0.0, 0.0, 7.0, 2170.0, 1350.0), false),
        );
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('proyecta el metrado aún sin sector junto con los sectores abiertos', function () {
        $proyecto = obra(['fecha_inicio' => '2026-10-01']);
        $r210 = resistencia(210);
        $grupo = GrupoPartida::create(['nombre' => 'Estructuras', 'orden' => 1]);
        $p = partidaEat($proyecto, $grupo, '01.01', 'Placas', $r210, 10, 100);
        $nivel = $proyecto->niveles()->create(['nombre' => 'Piso 1', 'orden' => 1]);
        $culminado = sectorEat($nivel->id, $p->id, 'SA', 6, '2026-10-15');
        $abierto = sectorEat($nivel->id, $p->id, 'SB', 2);
        guiaEat($proyecto, '2026-10-12', $r210, 6.5, null, [$culminado->id => 6.5]);
        guiaEat($proyecto, '2026-10-20', $r210, 1, null, [$abierto->id => 1]);

        $r = reporteEat(asignadoA($proyecto), $proyecto, '2026-10-31')->assertOk();

        // Sin facturas: todo a 100. Real 6.5 × 100 = 650. Por ejecutar (10 − 6) × 1.1 = 4.4 m³: SB (2) y
        // los 2 m³ sin sector, aunque SB ya tenga 1 m³. 4.4 × 100 = 440; EAT 650 + 440 = 1090.
        expect($r->json('data.grupos.0.filas.0'))->toBe(
            filaEat($p->id, '01.01', "Placas · f'c 210 kg/cm²", montosEat(11.0, 1100.0, 7.5, 750.0, 350.0, 6.5, 650.0, 4.4, 440.0, 10.9, 1090.0, 10.0), false),
        );
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('una guía facturada toma el precio de su factura aunque la factura sea posterior al corte', function () {
        $e = escenarioEat();

        // Al 10-08 aún no hay facturas; la guía del 10-05 está en F1 (10-10): 4 × 310 = 1240.
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-08')->assertOk();
        expect($r->json('data.grupos.0.filas.0.consumido_soles'))->toBe(1240.0);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('una guía cuya factura no tiene su resistencia toma el último precio facturado', function () {
        $e = escenarioEat();
        // F2 solo factura 210: esta guía de 280 toma el último precio facturado de 280 al corte, 360 de F1.
        guiaEat($e['proyecto'], '2026-10-25', $e['r280'], 1, $e['f2'], [$e['s2']->id => 1]);

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        // 9 × 360 + 1 × 360 = 3600, todo real (S2 culminado).
        expect($r->json('data.grupos.0.filas.1.consumido_soles'))->toBe(3600.0);
        expect($r->json('data.grupos.0.filas.1.real_soles'))->toBe(3600.0);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('una guía sin factura toma el último precio facturado del proyecto al corte, no el de otra obra', function () {
        $e = escenarioEat();

        // Al 10-31: la guía del 10-20 toma 310 de F1 (10-10), no el 999 de la otra obra (10-15).
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();
        expect($r->json('data.grupos.0.filas.0.consumido_soles'))->toBe(2170.0); // 4 × 310 + 3 × 310
        expect($r->json('data.grupos.1.filas.0.consumido_soles'))->toBe(930.0); // 2 × 310 + 1 × 310
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('con dos facturas de la misma fecha toma el precio de la registrada después', function () {
        $e = escenarioEat();
        facturaEat($e['proyecto'], '2026-10-10', [[$e['r210'], 315]]);

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        // Consumido 4 × 310 (su factura F1) + 3 × 315 = 2185. Proyecta 11 × 315 = 3465.
        expect($r->json('data.grupos.0.filas.0.consumido_soles'))->toBe(2185.0);
        expect($r->json('data.grupos.0.filas.0.proyectado_soles'))->toBe(3465.0);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('valoriza las guías sin sector sin precio facturado con el promedio del presupuesto ponderado por metrado', function () {
        $e = escenarioEat();
        guiaEat($e['proyecto'], '2026-10-07', $e['r210'], 2, null);

        // Al 10-08 no hay facturas: promedio de 210 = (10 × 320 + 5 × 260) / 15 = 4500 / 15 = 300; 2 × 300 = 600
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-08')->assertOk();
        expect($r->json('data.filas_aparte'))->toBe([sinSectorEat(2.0, 600.0)]);

        // Al 10-31: (1 + 2) × 310 = 930
        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();
        expect($r->json('data.filas_aparte.0'))->toBe(sinSectorEat(3.0, 930.0));
        expect(DB::table('reportes_generados')->count())->toBe(2);
    });

    it('una guía sin sector de una resistencia que no está en el presupuesto ni facturada vale 0', function () {
        $e = escenarioEat();
        guiaEat($e['proyecto'], '2026-10-07', resistencia(350), 1.5, null);

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-08')->assertOk();

        expect($r->json('data.filas_aparte'))->toBe([sinSectorEat(1.5, 0.0)]);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('redondea cada fila al céntimo con la mitad hacia arriba y suma las filas ya redondeadas', function () {
        $proyecto = obra(['fecha_inicio' => '2026-10-01']);
        $r210 = resistencia(210);
        $grupo = GrupoPartida::create(['nombre' => 'Estructuras', 'orden' => 1]);
        $a = partidaEat($proyecto, $grupo, '01.01', 'Columnas', $r210, 2, 100, 0);
        $b = partidaEat($proyecto, $grupo, '01.02', 'Placas', $r210, 2, 100, 0);
        $nivel = $proyecto->niveles()->create(['nombre' => 'Piso 1', 'orden' => 1]);
        $sa = sectorEat($nivel->id, $a->id, 'SA', 1.05, '2026-10-20');
        $sb = sectorEat($nivel->id, $b->id, 'SB', 1.05, '2026-10-20');
        $factura = facturaEat($proyecto, '2026-10-10', [[$r210, 310.5]]);
        guiaEat($proyecto, '2026-10-11', $r210, 1.05, $factura, [$sa->id => 1.05]);
        guiaEat($proyecto, '2026-10-12', $r210, 1.05, $factura, [$sb->id => 1.05]);

        $r = reporteEat(asignadoA($proyecto), $proyecto, '2026-10-31')->assertOk();

        // Sin desperdicio. Real: 1.05 × 310.50 = 326.025 → 326.03. Saldo: 200 − 326.03 = −126.03.
        // Proyectado: 0.95 × 310.50 = 294.975 → 294.98. EAT 326.03 + 294.98 = 621.01; variación −421.01.
        $fila = montosEat(2.0, 200.0, 1.05, 326.03, -126.03, 1.05, 326.03, 0.95, 294.98, 2.0, 621.01, -421.01);
        expect($r->json('data.grupos.0.filas.0'))->toBe(filaEat($a->id, '01.01', "Columnas · f'c 210 kg/cm²", $fila, true));
        expect($r->json('data.grupos.0.filas.1'))->toBe(filaEat($b->id, '01.02', "Placas · f'c 210 kg/cm²", $fila, true));
        // Suma de filas redondeadas: 326.03 × 2 = 652.06 (no 652.05); 621.01 × 2 = 1242.02 (no 1242.00).
        $suma = montosEat(4.0, 400.0, 2.1, 652.06, -252.06, 2.1, 652.06, 1.9, 589.96, 4.0, 1242.02, -842.02);
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
        expect($r->json('data.grupos.1.filas.0.proyectado_soles'))->toBe(1760.0); // 5.5 × 320
        expect(array_column($r->json('data.filas_aparte'), 'concepto'))->toBe(['Guías sin sector', 'Bombeo', 'Espera']);
        expect(array_column($r->json('data.filas_aparte'), 'consumido_soles'))->toBe([320.0, 150.0, 80.0]);
        expect(DB::table('reportes_generados')->count())->toBe(2);
    });

    it('muestra aparte las guías sin sector sin sumarlas, y los cargos facturados sumando sin proyectarse', function () {
        $e = escenarioEat();

        $r = reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk();

        expect($r->json('data.filas_aparte'))->toBe([sinSectorEat(1.0, 310.0), cargoEat('Bombeo', 150.0)]);
        // Subtotales más Bombeo, sin los 310 de la guía sin sector: 5410 + 930 + 150 = 6490
        $subtotales = $r->json('data.grupos.0.subtotal.consumido_soles') + $r->json('data.grupos.1.subtotal.consumido_soles');
        expect($r->json('data.total.consumido_soles'))->toBe($subtotales + 150.0)->toBe(6490.0);
        // El proyectado es solo concreto: 3410 + 1705, sin bombeo por lo que falta.
        expect($r->json('data.total.proyectado_soles'))->toBe(5115.0);
        expect($r->json('data.total.eat_soles'))->toBe(8505.0);
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

        reporteEat($e['usuario'], $e['proyecto'], '2026-10-31')->assertOk()->assertJsonPath('data.total.eat_soles', 8505.0);
        reporteEat(asignadoA($e['proyecto'], Rol::Gerencia), $e['proyecto'], '2026-10-31')->assertOk()->assertJsonPath('data.total.eat_soles', 8505.0);
        reporteEat(conRol(Rol::Administrador), $e['proyecto'], '2026-10-31')->assertOk()->assertJsonPath('data.total.eat_soles', 8505.0);
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
            [AVISO_CARGOS_EAT],
            ['Elemento', 'Presup. m³', 'Presup. S/', 'Consumido m³', 'Consumido S/', 'Saldo S/', 'Real culminado S/', 'Proyectado m³', 'Proyectado S/', 'EAT S/', 'Variación S/'],
            ['Estructuras'],
            ["01.01 Columnas · f'c 210 kg/cm²", 11.0, 3520.0, 7.0, 2170.0, 1350.0, 0.0, 11.0, 3410.0, 3410.0, 110.0],
            ["01.02 Vigas · f'c 280 kg/cm²", 8.8, 3080.0, 9.0, 3240.0, -160.0, 3240.0, 0.0, 0.0, 3240.0, -160.0],
            ['Subtotal Estructuras', 19.8, 6600.0, 16.0, 5410.0, 1190.0, 3240.0, 11.0, 3410.0, 6650.0, -50.0],
            ['Losas'],
            ["02.01 Losa aligerada · f'c 210 kg/cm²", 5.5, 1430.0, 3.0, 930.0, 500.0, 0.0, 5.5, 1705.0, 1705.0, -275.0],
            ['Subtotal Losas', 5.5, 1430.0, 3.0, 930.0, 500.0, 0.0, 5.5, 1705.0, 1705.0, -275.0],
            ['Guías sin sector', 0.0, 0.0, 1.0, 310.0, '—', '—', '—', '—', '—', '—'],
            [AVISO_SIN_SECTOR_EAT],
            ['Bombeo', 0.0, 0.0, '—', 150.0, -150.0, 150.0, '—', 0.0, 150.0, -150.0],
            ['Total', 25.3, 8030.0, 19.0, 6490.0, 1540.0, 3390.0, 16.5, 5115.0, 8505.0, -475.0],
        ]);
        // Variación negativa: la variación del total va en rojo; la de un cargo no, porque no es sobrecosto.
        expect(fuenteExcelEat($r->streamedContent(), 'Total', 'K'))->toBe(['negrita' => true, 'rojo' => true]);
        expect(fuenteExcelEat($r->streamedContent(), 'Bombeo', 'K'))->toBe(['negrita' => false, 'rojo' => false]);
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
        // Variación −475: la última celda del total lleva la clase de sobrecosto.
        $html = view('reportes.eat', $datos)->render();
        $total = collect(filasHtmlEat($html))->last();
        expect($total['clase'])->toBe('total');
        expect(end($total['celdas']))->toBe(['-475.00', 'monto sobrecosto']);
        expect($html)->toContain(AVISO_CARGOS_EAT);
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
        expect($r->json('data.filas_aparte.0'))->toBe(sinSectorEat(2.0, 620.0));
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
        expect($filas['Subtotal =1+1'])->toBe(['Subtotal =1+1', 19.8, 6600.0, 16.0, 5410.0, 1190.0, 3240.0, 11.0, 3410.0, 6650.0, -50.0]);
        expect($filas["01.02 Vigas · f'c 280 kg/cm²"])->toBe(["01.02 Vigas · f'c 280 kg/cm²", 8.8, 3080.0, 9.0, 3240.0, -160.0, 3240.0, 0.0, 0.0, 3240.0, -160.0]);
        expect($filas["'+HIPERVINCULO(\"http://x\")"])->toBe(["'+HIPERVINCULO(\"http://x\")", 0.0, 0.0, '—', 150.0, -150.0, 150.0, '—', 0.0, 150.0, -150.0]);
        expect($filas["'-2+3"])->toBe(["'-2+3", 0.0, 0.0, '—', 10.0, -10.0, 10.0, '—', 0.0, 10.0, -10.0]);
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
 * Cuatro partidas sin consumo (605 m³ netos; con 10 % de desperdicio, 665.5 m³ y S/ 252 945), ninguna factura y una guía f'c 210 de 6 m³ sin sector:
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

/** El nodo como elemento; si la consulta no lo encontró, la prueba falla diciendo qué faltaba. */
function elementoEat(DOMNode|false|null $nodo, string $que): DOMElement
{
    if (! $nodo instanceof DOMElement) {
        throw new UnexpectedValueException("No se encontró {$que}.");
    }

    return $nodo;
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
    $fila = elementoEat($xh->query("//m:row[m:c[1]//m:t[. = '{$texto}']]")->item(0), "la fila «{$texto}»");
    $celda = elementoEat($xh->query("m:c[@r = '{$columna}{$fila->getAttribute('r')}']", $fila)->item(0), "la celda {$columna} de «{$texto}»");

    $xe = new DOMXPath($estilos);
    $xe->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $xf = elementoEat($xe->query('//m:cellXfs/m:xf')->item((int) $celda->getAttribute('s')), 'el estilo de la celda');
    $fuente = elementoEat($xe->query('//m:fonts/m:font')->item((int) $xf->getAttribute('fontId')), 'la fuente de la celda');
    $color = $xe->query('m:color', $fuente)->item(0);
    $color = $color instanceof DOMElement ? $color->getAttribute('rgb') : '';

    return ['negrita' => $xe->query('m:b', $fuente)->length > 0, 'rojo' => str_ends_with(strtoupper($color), 'FF0000')];
}

/** Las filas de la tabla del HTML del PDF: la clase de cada fila y, por celda, su texto y su clase. */
function filasHtmlEat(string $html): array
{
    $documento = new DOMDocument;
    $documento->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR);

    $filas = [];
    foreach ((new DOMXPath($documento))->query('//tbody/tr') as $tr) {
        $tr = elementoEat($tr, 'la fila de la tabla');
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
        expect($r->json('data.filas_aparte'))->toBe([sinSectorEat(6.0, 2218.91)]);
        // Sin consumo asignado, el EAT es el presupuesto meta: 605 × 1.1 = 665.5 m³ y S/ 252 945
        // (231 × 385 + 198 × 375 + 104.5 × 360 + 132 × 395), sin los 6 m³ ni los S/ 2218.91.
        expect($r->json('data.total'))->toBe([...montosEat(665.5, 252945.0, 0.0, 0.0, 252945.0, 0.0, 0.0, 665.5, 252945.0, 665.5, 252945.0, 0.0), 'sobrecosto' => false]);
        expect(DB::table('reportes_generados')->count())->toBe(1);
    });

    it('en el Excel el total no suma la guía sin sector y el aviso va en la fila de abajo, sin rojo ni negrita', function () {
        $e = escenarioSoloGuiaSinSector();

        $contenido = exportarEat($e['usuario'], $e['proyecto'], 'excel', '2026-11-09')->assertOk()->streamedContent();

        expect(array_slice(filasExcelEat($contenido), -3))->toBe([
            ['Guías sin sector', 0.0, 0.0, 6.0, 2218.91, '—', '—', '—', '—', '—', '—'],
            [AVISO_SIN_SECTOR_EAT],
            ['Total', 665.5, 252945.0, 0.0, 0.0, 252945.0, 0.0, 665.5, 252945.0, 252945.0, 0.0],
        ]);
        expect(fuenteExcelEat($contenido, 'Total', 'K'))->toBe(['negrita' => true, 'rojo' => false]);
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
                ['—', 'monto'], ['—', 'monto'], ['—', 'monto'], ['—', 'monto'], ['—', 'monto'], ['—', 'monto'],
            ]],
            ['clase' => 'aviso', 'celdas' => [[AVISO_SIN_SECTOR_EAT, '']]],
            ['clase' => 'total', 'celdas' => [
                ['Total', ''], ['665.50', 'monto'], ['252,945.00', 'monto'], ['0.00', 'monto'], ['0.00', 'monto'],
                ['252,945.00', 'monto'], ['0.00', 'monto'], ['665.50', 'monto'], ['252,945.00', 'monto'],
                ['252,945.00', 'monto'], ['0.00', 'monto'],
            ]],
        ]);
    });
});
