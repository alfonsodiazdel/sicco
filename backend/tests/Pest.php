<?php

use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use App\Enums\Rol;
use App\Models\Asignacion;
use App\Models\Concreto;
use App\Models\ElementoEstructural;
use App\Models\Factura;
use App\Models\GrupoPartida;
use App\Models\GuiaRemision;
use App\Models\Partida;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\Resistencia;
use App\Models\User;
use App\Services\Documentos\CupoExportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Un ZIP que la prueba no lee deja su cupo tomado en la conexión, y esa conexión sobrevive a la prueba.
    ->afterEach(function () {
        if (DB::getDriverName() === 'mysql') {
            DB::selectOne('SELECT RELEASE_ALL_LOCKS() AS n');
        }
    })
    ->in('Feature');

// Ayudas compartidas por las pruebas del presupuesto (grupos y partidas).

function conRol(Rol $rol): User
{
    return User::factory()->rol($rol)->create();
}

function obra(array $atributos = []): Proyecto
{
    return Proyecto::create([
        'codigo' => 'PRY-001',
        'nombre' => 'Residencial Los Álamos',
        'direccion' => 'Av. Primavera 1250, Santiago de Surco',
        'fecha_inicio' => '2026-03-02',
        'fecha_fin' => '2027-02-26',
        'ingeniero_id' => conRol(Rol::IngenieroProduccion)->id,
        'estado' => 'Activo',
        ...$atributos,
    ]);
}

/**
 * Datos válidos de una partida. Deja admitido el elemento en el grupo (`grupo_elemento`), como lo
 * haría Oficina técnica en el catálogo; las pruebas de esa regla lo quitan después.
 */
function datosPartida(array $cambios = []): array
{
    $datos = [
        'grupo_id' => $cambios['grupo_id'] ?? GrupoPartida::firstOrCreate(
            ['nombre' => 'Cimentación'],
            ['partida_control' => '2.02.02', 'orden' => 2],
        )->id,
        'item' => '03.01.02.01',
        'descripcion' => "CONCRETO F'C=210 kg/cm2 - ZAPATAS",
        'elemento' => 'Zapata',
        'resistencia_id' => resistencia(210)->id,
        'metrado_m3' => 320,
        'factor_desperdicio' => 5,
        'precio_unitario' => 385,
        ...$cambios,
    ];
    $elemento = ElementoEstructural::where('nombre', $datos['elemento'])->first();
    if ($elemento && is_int($datos['grupo_id'])) {
        GrupoPartida::find($datos['grupo_id'])?->elementos()->syncWithoutDetaching([$elemento->id]);
    }

    return $datos;
}

/**
 * Crea la partida en el proyecto como lo hace la API: `resistencia_id` y `precio_unitario` van a
 * su concreto (si ya existe, se le cambia el precio).
 */
function partida(Proyecto $proyecto, array $datos): Partida
{
    $concreto = Concreto::fijar($proyecto->id, $datos['resistencia_id'], $datos['precio_unitario']);
    unset($datos['resistencia_id'], $datos['precio_unitario'], $datos['proyecto_id']);

    return $proyecto->partidas()->create([...$datos, 'concreto_id' => $concreto->id]);
}

/** La resistencia del catálogo con ese f'c (en producción la carga ResistenciaSeeder). */
function resistencia(int $fc): Resistencia
{
    return Resistencia::firstOrCreate(['fc_kg_cm2' => $fc], ['nombre' => "f'c {$fc} kg/cm²"]);
}

/** Usuario con el rol dado y asignado al proyecto (USUARIO_PROYECTO). */
function asignadoA(Proyecto $proyecto, Rol $rol = Rol::OficinaTecnica): User
{
    $usuario = User::factory()->rol($rol)->create();
    $proyecto->usuarios()->attach($usuario->id);

    return $usuario;
}

// Guías y facturas con fecha, para los reportes (CU16) y el dashboard (CU17).

/** @param list<array{0: Resistencia, 1: float}> $lineas */
function facturaCorte(Proyecto $proyecto, string $numero, string $fecha, array $lineas, float $volumen, float $subtotal, array $cargos = []): Factura
{
    $factura = Factura::create([
        'proyecto_id' => $proyecto->id, 'proveedor_id' => Proveedor::factory()->create()->id,
        'numero' => $numero, 'fecha' => $fecha, 'volumen_m3' => $volumen,
        'subtotal' => $subtotal, 'igv' => round($subtotal * 0.18, 2), 'total' => round($subtotal * 1.18, 2),
        'archivo_ruta' => "facturas/{$proyecto->id}/f.pdf", 'registrado_por' => conRol(Rol::OficinaTecnica)->id,
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
function guiaCorte(Proyecto $proyecto, string $numero, string $fecha, Resistencia $r, float $volumen, ?Factura $factura, array $reparto = []): GuiaRemision
{
    $usuario = conRol(Rol::Almacenero);
    $asignado = array_sum($reparto);
    $guia = GuiaRemision::create([
        'proyecto_id' => $proyecto->id, 'proveedor_id' => Proveedor::factory()->create(['razon_social' => "Concretera {$numero}"])->id,
        'numero' => $numero, 'fecha' => $fecha, 'resistencia_id' => $r->id, 'volumen_m3' => $volumen,
        'foto_ruta' => "guias/{$proyecto->id}/foto.jpg",
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

/** Si nadie tiene tomado el cupo de exportación (1..N); un cupo de esta misma conexión cuenta como tomado. */
function cupoLibre(int $cupo): bool
{
    return (int) DB::selectOne('SELECT IS_FREE_LOCK(?) AS libre', [CupoExportacion::nombre($cupo)])->libre === 1;
}

/** Toma todos los cupos desde otra conexión, como otro proceso de PHP; se sueltan con DB::disconnect('otro_proceso'). */
function ocuparCuposDesdeOtroProceso(): void
{
    config(['database.connections.otro_proceso' => config('database.connections.'.config('database.default'))]);
    for ($cupo = 1; $cupo <= config('services.exportaciones.simultaneas'); $cupo++) {
        expect((int) DB::connection('otro_proceso')->selectOne('SELECT GET_LOCK(?, 0) AS t', [CupoExportacion::nombre($cupo)])->t)->toBe(1);
    }
}
