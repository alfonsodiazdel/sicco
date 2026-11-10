<?php

use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use App\Enums\Rol;
use App\Models\Factura;
use App\Models\GuiaRemision;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake();
});

function guiaParaExportar(Proyecto $proyecto, Proveedor $proveedor, string $numero, string $foto, array $atributos = []): GuiaRemision
{
    return GuiaRemision::create([
        'proyecto_id' => $proyecto->id,
        'proveedor_id' => $proveedor->id,
        'numero' => $numero,
        'fecha' => '2026-11-05',
        'resistencia_id' => resistencia(280)->id,
        'volumen_m3' => 8.5,
        'foto_ruta' => $foto,
        'estado_asignacion' => EstadoAsignacion::SinSector,
        'origen' => OrigenGuia::Manual,
        'registrado_por' => User::factory()->rol(Rol::Almacenero)->create()->id,
        ...$atributos,
    ]);
}

function facturaParaExportar(Proyecto $proyecto, Proveedor $proveedor, string $numero, string $archivo, array $atributos = []): Factura
{
    return Factura::create([
        'proyecto_id' => $proyecto->id,
        'proveedor_id' => $proveedor->id,
        'numero' => $numero,
        'fecha' => '2026-11-06',
        'volumen_m3' => 23.5,
        'subtotal' => 7026.5,
        'igv' => 1264.77,
        'total' => 8291.27,
        'diferencia_confirmada' => false,
        'origen' => 'manual',
        'archivo_ruta' => $archivo,
        'registrado_por' => User::factory()->rol(Rol::OficinaTecnica)->create()->id,
        ...$atributos,
    ]);
}

/** Las entradas del ZIP descargado, con su contenido. Borra el temporal: la prueba no lo envía. */
function entradasDelZip(TestResponse $respuesta): array
{
    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($ruta))->toBeTrue();
    $entradas = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nombre = $zip->getNameIndex($i);
        $entradas[$nombre] = $zip->getFromName($nombre);
    }
    $zip->close();
    unlink($ruta);
    ksort($entradas);

    return $entradas;
}

describe('listado de documentos para exportar (CU18)', function () {
    it('lista las guías del proyecto con los datos de la tabla, de la más reciente a la más antigua', function () {
        $proyecto = obra();
        $proveedor = Proveedor::factory()->create(['ruc' => '20100000001', 'razon_social' => 'Unicon S.A.']);
        $antigua = guiaParaExportar($proyecto, $proveedor, 'T001-00000101', "guias/{$proyecto->id}/a.jpg", ['fecha' => '2026-11-01']);
        $reciente = guiaParaExportar($proyecto, $proveedor, 'T001-00000102', "guias/{$proyecto->id}/b.jpg", [
            'estado_asignacion' => EstadoAsignacion::Parcial,
        ]);

        $this->actingAs(asignadoA($proyecto))
            ->getJson("/api/v1/proyectos/{$proyecto->id}/documentos/guias")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'id' => $reciente->id,
                'numero' => 'T001-00000102',
                'fecha' => '2026-11-05',
                'proveedor' => ['id' => $proveedor->id, 'ruc' => '20100000001', 'razon_social' => 'Unicon S.A.'],
                'volumen_m3' => 8.5,
                'estado_asignacion' => 'parcial',
                'tiene_foto' => true,
            ])
            ->assertJsonPath('data.1.id', $antigua->id);
    });

    it('filtra las guías por fechas, proveedor y estado', function () {
        $proyecto = obra();
        $proveedor = Proveedor::factory()->create();
        $buscada = guiaParaExportar($proyecto, $proveedor, 'T001-1', 'guias/x/1.jpg');
        guiaParaExportar($proyecto, $proveedor, 'T001-2', 'guias/x/2.jpg', ['fecha' => '2026-10-20']);
        guiaParaExportar($proyecto, $proveedor, 'T001-3', 'guias/x/3.jpg', ['estado_asignacion' => EstadoAsignacion::Asignada]);
        guiaParaExportar($proyecto, Proveedor::factory()->create(), 'T001-4', 'guias/x/4.jpg');

        $this->actingAs(asignadoA($proyecto))
            ->getJson("/api/v1/proyectos/{$proyecto->id}/documentos/guias?".http_build_query([
                'desde' => '2026-11-01', 'hasta' => '2026-11-30', 'proveedor_id' => $proveedor->id, 'estado' => 'sin_sector',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $buscada->id);
    });

    it('lista las facturas del proyecto con los datos de la tabla y sus filtros', function () {
        $proyecto = obra();
        $proveedor = Proveedor::factory()->create(['ruc' => '20100000001', 'razon_social' => 'Unicon S.A.']);
        $factura = facturaParaExportar($proyecto, $proveedor, 'F001-00002803', "facturas/{$proyecto->id}/f.pdf");
        facturaParaExportar($proyecto, $proveedor, 'F001-00002700', "facturas/{$proyecto->id}/g.pdf", ['fecha' => '2026-10-01']);

        $this->actingAs(asignadoA($proyecto))
            ->getJson("/api/v1/proyectos/{$proyecto->id}/documentos/facturas?desde=2026-11-01&proveedor_id={$proveedor->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', [
                'id' => $factura->id,
                'numero' => 'F001-00002803',
                'fecha' => '2026-11-06',
                'proveedor' => ['id' => $proveedor->id, 'ruc' => '20100000001', 'razon_social' => 'Unicon S.A.'],
                'volumen_m3' => 23.5,
                'total' => 8291.27,
                'tiene_archivo' => true,
            ]);
    });
});

describe('ZIP de documentos (CU18)', function () {
    it('descarga las fotos de las guías con nombres RUC-número y su manifiesto', function () {
        $proyecto = obra();
        $proveedor = Proveedor::factory()->create(['ruc' => '20100000001']);
        Storage::put("guias/{$proyecto->id}/a.jpg", 'foto-a');
        Storage::put("guias/{$proyecto->id}/b.png", 'foto-b');
        guiaParaExportar($proyecto, $proveedor, 'T001-00000101', "guias/{$proyecto->id}/a.jpg");
        guiaParaExportar($proyecto, $proveedor, 'T001-00000102', "guias/{$proyecto->id}/b.png");

        $respuesta = $this->actingAs(asignadoA($proyecto))
            ->get("/api/v1/proyectos/{$proyecto->id}/documentos/guias/zip?desde=2026-11-01&hasta=2026-11-30")
            ->assertOk()
            ->assertDownload('guias-PRY-001-2026-11-01_2026-11-30.zip');

        $entradas = entradasDelZip($respuesta);
        expect(array_keys($entradas))->toBe([
            '20100000001-T001-00000101.jpg',
            '20100000001-T001-00000102.png',
            'manifest.txt',
        ])
            ->and($entradas['20100000001-T001-00000101.jpg'])->toBe('foto-a')
            ->and($entradas['20100000001-T001-00000102.png'])->toBe('foto-b')
            ->and($entradas['manifest.txt'])
            ->toContain("Exportación de guías de remisión\nProyecto: PRY-001 Residencial Los Álamos\n")
            ->toContain("Pedido: desde 2026-11-01, hasta 2026-11-30\nDocumentos encontrados: 2\n")
            ->toContain("Incluidos (2):\n- 20100000001-T001-00000102.png\n- 20100000001-T001-00000101.jpg\n")
            ->toContain("Faltantes (0):\n- ninguno\n");
    });

    it('descarga las facturas, en PDF o en imagen, con su manifiesto', function () {
        $proyecto = obra();
        $unicon = Proveedor::factory()->create(['ruc' => '20100000001']);
        $mixercon = Proveedor::factory()->create(['ruc' => '20500000005', 'razon_social' => 'Mixercon S.A.']);
        Storage::put("facturas/{$proyecto->id}/f.pdf", '%PDF-1.4 factura');
        Storage::put("facturas/{$proyecto->id}/g.jpg", 'imagen-factura');
        $pdf = facturaParaExportar($proyecto, $unicon, 'F001-00002803', "facturas/{$proyecto->id}/f.pdf");
        $imagen = facturaParaExportar($proyecto, $mixercon, 'E001-77', "facturas/{$proyecto->id}/g.jpg");

        $respuesta = $this->actingAs(asignadoA($proyecto))
            ->get("/api/v1/proyectos/{$proyecto->id}/documentos/facturas/zip?".http_build_query(['ids' => [$pdf->id, $imagen->id]]))
            ->assertOk()
            ->assertDownload('facturas-PRY-001-seleccion.zip');

        $entradas = entradasDelZip($respuesta);
        expect(array_keys($entradas))->toBe([
            '20100000001-F001-00002803.pdf',
            '20500000005-E001-77.jpg',
            'manifest.txt',
        ])
            ->and($entradas['20100000001-F001-00002803.pdf'])->toBe('%PDF-1.4 factura')
            ->and($entradas['20500000005-E001-77.jpg'])->toBe('imagen-factura')
            ->and($entradas['manifest.txt'])
            ->toContain("Exportación de facturas\n")
            ->toContain("Pedido: selección de 2 documentos (id {$pdf->id}, {$imagen->id})\nDocumentos encontrados: 2\n")
            ->toContain("Faltantes (0):\n- ninguno\n");
    });
});

/** Un proyecto con una guía para exportar y la URL de su ZIP. */
function zipDeUnaGuia(): array
{
    $proyecto = obra();
    Storage::put("guias/{$proyecto->id}/a.jpg", 'foto-a');
    guiaParaExportar($proyecto, Proveedor::factory()->create(), 'T001-00000101', "guias/{$proyecto->id}/a.jpg");

    return [$proyecto, "/api/v1/proyectos/{$proyecto->id}/documentos/guias/zip?desde=2026-11-01&hasta=2026-11-30"];
}

describe('límite de exportaciones en ZIP (CU18)', function () {
    it('permite 5 exportaciones por minuto a cada usuario y la sexta responde 429', function () {
        [$proyecto, $url] = zipDeUnaGuia();
        $usuario = asignadoA($proyecto);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($usuario)->get($url)->assertOk();
        }
        $this->actingAs($usuario)->get($url)->assertStatus(429);

        // El cupo es de cada usuario: otro usuario del mismo proyecto sigue exportando.
        $this->actingAs(asignadoA($proyecto))->get($url)->assertOk();
    });

    it('gastar el cupo del reporte EAT no bloquea la exportación', function () {
        config(['services.reportes.por_minuto' => 2]);
        [$proyecto, $url] = zipDeUnaGuia();
        $usuario = asignadoA($proyecto);

        // Sin fecha de corte responde 422, pero la petición igual cuenta para el límite del reporte.
        $reporte = "/api/v1/proyectos/{$proyecto->id}/reportes/eat";
        $this->actingAs($usuario)->getJson($reporte)->assertStatus(422);
        $this->actingAs($usuario)->getJson($reporte)->assertStatus(422);
        $this->actingAs($usuario)->getJson($reporte)->assertStatus(429);

        $this->actingAs($usuario)->get($url)->assertOk();
    });

    it('usa 5 por minuto cuando EXPORTACIONES_POR_MINUTO está vacía o no es un número válido', function (string $valor) {
        $_ENV['EXPORTACIONES_POR_MINUTO'] = $_SERVER['EXPORTACIONES_POR_MINUTO'] = $valor;
        try {
            config(['services.exportaciones' => (require config_path('services.php'))['exportaciones']]);
        } finally {
            unset($_ENV['EXPORTACIONES_POR_MINUTO'], $_SERVER['EXPORTACIONES_POR_MINUTO']);
        }
        [$proyecto, $url] = zipDeUnaGuia();
        $usuario = asignadoA($proyecto);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($usuario)->get($url)->assertOk();
        }
        $this->actingAs($usuario)->get($url)->assertStatus(429);
    })->with(['vacía' => '', 'texto' => 'cinco', 'cero' => '0']);
});
