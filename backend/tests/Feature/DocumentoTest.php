<?php

use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use App\Enums\Rol;
use App\Models\Factura;
use App\Models\GuiaRemision;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Documentos\ArmadorZip;
use Illuminate\Support\Facades\Cache;
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

/** Las entradas del ZIP descargado, con su contenido. */
function entradasDelZip(TestResponse|string $respuesta): array
{
    $ruta = tempnam(sys_get_temp_dir(), 'prueba');
    file_put_contents($ruta, is_string($respuesta) ? $respuesta : $respuesta->streamedContent());
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

/** Salida de prueba: cada escritura llama a $alEscribir, que puede adelantar el reloj. */
class SalidaLentaDePrueba
{
    public static Closure $alEscribir;

    public $context;

    public function stream_open(string $ruta, string $modo, int $opciones, ?string &$abierta): bool
    {
        return true;
    }

    public function stream_write(string $datos): int
    {
        (self::$alEscribir)();

        return strlen($datos);
    }

    public function stream_eof(): bool
    {
        return false;
    }
}

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
            $this->actingAs($usuario)->get($url)->assertOk()->streamedContent();
        }
        $this->actingAs($usuario)->get($url)->assertStatus(429);

        // El cupo es de cada usuario: otro usuario del mismo proyecto sigue exportando.
        $this->actingAs(asignadoA($proyecto))->get($url)->assertOk()->streamedContent();
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

        $this->actingAs($usuario)->get($url)->assertOk()->streamedContent();
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
            $this->actingAs($usuario)->get($url)->assertOk()->streamedContent();
        }
        $this->actingAs($usuario)->get($url)->assertStatus(429);
    })->with(['vacía' => '', 'texto' => 'cinco', 'cero' => '0']);
});

describe('ZIP en streaming, tope de peso y cupo de exportaciones (CU18)', function () {
    it('arma el ZIP sin escribir archivos temporales', function () {
        [$proyecto, $url] = zipDeUnaGuia();
        $antes = scandir(sys_get_temp_dir());

        $contenido = $this->actingAs(asignadoA($proyecto))->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('X-Accel-Buffering', 'no')
            ->streamedContent();

        expect(scandir(sys_get_temp_dir()))->toBe($antes)
            ->and(array_values(entradasDelZip($contenido)))->toContain('foto-a');
    });

    it('acepta un ZIP que pesa justo el máximo y rechaza con 422 uno que lo pasa, antes de enviar nada', function () {
        config(['services.exportaciones.max_mb' => 1]);
        $proyecto = obra();
        $proveedor = Proveedor::factory()->create();
        Storage::put("guias/{$proyecto->id}/a.jpg", str_repeat('a', 512 * 1024));
        Storage::put("guias/{$proyecto->id}/b.jpg", str_repeat('b', 512 * 1024));
        guiaParaExportar($proyecto, $proveedor, 'T001-00000101', "guias/{$proyecto->id}/a.jpg");
        guiaParaExportar($proyecto, $proveedor, 'T001-00000102', "guias/{$proyecto->id}/b.jpg");
        $url = "/api/v1/proyectos/{$proyecto->id}/documentos/guias/zip?desde=2026-11-01&hasta=2026-11-30";
        $usuario = asignadoA($proyecto);

        expect($this->actingAs($usuario)->get($url)->assertOk()->streamedContent())->toStartWith('PK');

        Storage::put("guias/{$proyecto->id}/b.jpg", str_repeat('b', 512 * 1024 + 1));
        $this->actingAs($usuario)->get($url)
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => 'El ZIP pesaría 2 MB y el máximo es 1 MB. Acote los filtros o la selección, por ejemplo con un rango de fechas menor.',
                'code' => 'demasiado_peso',
            ]);
        // El rechazo no ocupa un cupo.
        expect(Cache::lock('exportar-zip:0', 1)->get())->toBeTrue();
    });

    it('responde 429 cuando las 2 exportaciones simultáneas están en curso y deja pasar al liberarse una', function () {
        [$proyecto, $url] = zipDeUnaGuia();
        $usuario = asignadoA($proyecto);
        $primera = Cache::lock('exportar-zip:0', 300);
        $segunda = Cache::lock('exportar-zip:1', 300);
        expect($primera->get())->toBeTrue()->and($segunda->get())->toBeTrue();

        $this->actingAs($usuario)->get($url)
            ->assertStatus(429)
            ->assertExactJson([
                'message' => 'Hay otras exportaciones en curso. Intente de nuevo en un minuto.',
                'code' => 'sin_cupo',
            ]);

        $segunda->release();
        $this->actingAs($usuario)->get($url)->assertOk()->streamedContent();
    });

    it('libera el cupo aunque el armado del ZIP falle a la mitad', function () {
        [$proyecto, $url] = zipDeUnaGuia();
        app()->bind(ArmadorZip::class, fn () => new class extends ArmadorZip
        {
            public function transmitir(array $documentos, array $encabezado, ?callable $alAvanzar = null, $salida = null): void
            {
                echo 'PK';
                throw new RuntimeException('se cayó el disco');
            }
        });
        $respuesta = $this->actingAs(asignadoA($proyecto))->get($url)->assertOk();

        // streamedContent() deja abierto su buffer de salida si el envío lanza una excepción.
        $nivel = ob_get_level();
        try {
            $respuesta->streamedContent();
            $error = null;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } finally {
            while (ob_get_level() > $nivel) {
                ob_end_clean();
            }
        }

        expect($error)->toBe('se cayó el disco')
            ->and(Cache::lock('exportar-zip:0', 1)->get())->toBeTrue();
    });

    it('si el proceso muere sin liberar el cupo, este vence a los 300 segundos sin avanzar', function () {
        [$proyecto, $url] = zipDeUnaGuia();
        $usuario = asignadoA($proyecto);
        // Sin leer la respuesta el ZIP nunca se envía: es como un proceso que murió con el cupo tomado.
        $this->actingAs($usuario)->get($url)->assertOk();
        $this->actingAs($usuario)->get($url)->assertOk();
        $this->actingAs($usuario)->get($url)->assertStatus(429);

        $this->travel(299)->seconds();
        $this->actingAs($usuario)->get($url)->assertStatus(429);
        $this->travel(2)->seconds();
        $this->actingAs($usuario)->get($url)->assertOk()->streamedContent();
    });

    it('una descarga lenta conserva el cupo mientras sigue enviando archivos', function () {
        [$proyecto, $url] = zipDeUnaGuia();
        $tomado = new ArrayObject;
        $prueba = $this;
        app()->bind(ArmadorZip::class, fn () => new class($prueba, $tomado) extends ArmadorZip
        {
            public function __construct(private $prueba, private ArrayObject $tomado) {}

            public function transmitir(array $documentos, array $encabezado, ?callable $alAvanzar = null, $salida = null): void
            {
                foreach ([1, 2, 3] as $archivo) {
                    $this->prueba->travel(200)->seconds();
                    $alAvanzar();
                    $this->tomado[] = ! Cache::lock('exportar-zip:0', 1)->get();
                }
            }
        });

        $this->actingAs(asignadoA($proyecto))->get($url)->assertOk()->streamedContent();

        // 600 s en total, más que los 300 del plazo, y el cupo nunca quedó libre a mitad.
        expect($tomado->getArrayCopy())->toBe([true, true, true])
            ->and(Cache::lock('exportar-zip:0', 1)->get())->toBeTrue();
    });

    it('un solo archivo que tarda más que el plazo del candado en salir conserva el cupo hasta el final', function () {
        [$proyecto, $url] = zipDeUnaGuia();
        $tomado = [];
        SalidaLentaDePrueba::$alEscribir = function () use (&$tomado) {
            $this->travel(200)->seconds();
            $sonda = Cache::lock('exportar-zip:0', 1);
            $tomado[] = ! $sonda->get();
            if (! end($tomado)) {
                $sonda->release();
            }
        };
        app()->bind(ArmadorZip::class, fn () => new class extends ArmadorZip
        {
            public function transmitir(array $documentos, array $encabezado, ?callable $alAvanzar = null, $salida = null): void
            {
                parent::transmitir($documentos, $encabezado, $alAvanzar, fopen('lento://zip', 'wb'));
            }
        });
        stream_wrapper_register('lento', SalidaLentaDePrueba::class);
        try {
            $this->actingAs(asignadoA($proyecto))->get($url)->assertOk()->streamedContent();
        } finally {
            stream_wrapper_unregister('lento');
        }

        // Ya entre el encabezado y el contenido del único archivo pasan más de 300 s.
        expect(count($tomado) * 200)->toBeGreaterThan(300)
            ->and($tomado)->toBe(array_fill(0, count($tomado), true))
            ->and(Cache::lock('exportar-zip:0', 1)->get())->toBeTrue();
    });

    it('lista como faltante un archivo que llega incompleto, y el ZIP sigue siendo válido', function () {
        Storage::put('guias/a.jpg', 'foto-a');
        Storage::put('guias/b.jpg', 'foto-b');
        $salida = fopen('php://memory', 'w+b');

        (new ArmadorZip)->transmitir([
            ['ruc' => '20100000001', 'numero' => 'T001-1', 'ruta' => 'guias/a.jpg', 'bytes' => 6],
            // Pesaba 100 bytes al medirlo y ahora se leen 6: la lectura se cortó.
            ['ruc' => '20100000001', 'numero' => 'T001-2', 'ruta' => 'guias/b.jpg', 'bytes' => 100],
            ['ruc' => '20100000001', 'numero' => 'T001-3', 'ruta' => 'guias/c.jpg', 'bytes' => null],
        ], ['Exportación de prueba'], salida: $salida);

        rewind($salida);
        $entradas = entradasDelZip(stream_get_contents($salida));
        expect($entradas['20100000001-T001-1.jpg'])->toBe('foto-a')
            ->and($entradas['manifest.txt'])
            ->toContain("Documentos encontrados: 3\n")
            ->toContain("Incluidos (1):\n- 20100000001-T001-1.jpg\n")
            ->toContain("Faltantes (2):\n- 20100000001-T001-2: incompleto (6 de 100 bytes) en 20100000001-T001-2.jpg\n- 20100000001-T001-3: no está en el disco\n");
    });

    it('usa 500 MB, 2 simultáneas y 300 segundos cuando sus variables están vacías o no son números válidos', function (string $valor) {
        $variables = ['EXPORTACION_ZIP_MAX_MB', 'EXPORTACIONES_SIMULTANEAS', 'EXPORTACION_ZIP_LOCK_SEGUNDOS'];
        foreach ($variables as $variable) {
            $_ENV[$variable] = $_SERVER[$variable] = $valor;
        }
        try {
            $exportaciones = (require config_path('services.php'))['exportaciones'];
        } finally {
            foreach ($variables as $variable) {
                unset($_ENV[$variable], $_SERVER[$variable]);
            }
        }

        expect($exportaciones)->toMatchArray(['max_mb' => 500, 'simultaneas' => 2, 'candado_segundos' => 300]);
    })->with(['vacía' => '', 'texto' => 'cinco', 'cero' => '0']);
});
