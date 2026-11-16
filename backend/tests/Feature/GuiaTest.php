<?php

use App\Contracts\OcrServiceInterface;
use App\Enums\EstadoAsignacion;
use App\Enums\Rol;
use App\Http\Resources\GuiaResource;
use App\Models\Factura;
use App\Models\GuiaRemision;
use App\Models\ProductoProveedor;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Ocr\LecturaFactura;
use App\Services\Ocr\LecturaGuia;
use App\Services\Ocr\LecturaNoDisponible;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

/** OCR que devuelve la lectura dada, o falla si es null. Guarda las indicaciones que recibe. */
function ocrQueLee(?array $datos): object
{
    $ocr = new class($datos) implements OcrServiceInterface
    {
        public array $indicaciones = [];

        public function __construct(private ?array $datos) {}

        public function leerGuia(string $imagen, string $mediaType, array $indicaciones = []): LecturaGuia
        {
            $this->indicaciones = $indicaciones;

            return $this->datos === null
                ? throw new LecturaNoDisponible('Sin respuesta')
                : LecturaGuia::desdeDatos($this->datos);
        }

        public function leerFactura(string $archivo, string $mediaType, array $indicaciones = []): LecturaFactura
        {
            throw new LecturaNoDisponible('No se usa en las guías');
        }
    };
    app()->instance(OcrServiceInterface::class, $ocr);

    return $ocr;
}

function lecturaUnicon(array $cambios = []): array
{
    $campo = fn ($valor, $confianza = 0.95) => ['valor' => $valor, 'confianza' => $confianza];

    return [
        'es_guia_remision' => true,
        'numero' => $campo('T001-00012345'),
        'fecha' => $campo(today()->toDateString()),
        'proveedor' => $campo('UNICON S.A.'),
        'ruc' => $campo('20100030595'),
        'codigo_producto' => $campo(null, 0),
        'descripcion_producto' => $campo("CONCRETO F'C 210 KG/CM2"),
        'resistencia' => $campo(210),
        'volumen_m3' => $campo(8.5),
        'placa' => $campo('ABC-123', 0.5),
        ...$cambios,
    ];
}

/** Holcim con su regla de código y un producto registrado: 70280NB5746 es f'c 280. */
function holcim(): Proveedor
{
    $holcim = Proveedor::factory()->create(['ruc' => '20380289360', 'razon_social' => 'HOLCIM PERU S.A.']);
    $holcim->forceFill([
        'patron_codigo' => '^70(?<fc>\d{3})',
        'indicaciones_ocr' => 'La resistencia está en el código del producto.',
    ])->save();
    $holcim->productos()->create(['codigo' => '70280NB5746', 'descripcion' => 'HOLCIM CLASICO', 'resistencia_id' => resistencia(280)->id]);

    return $holcim;
}

function lecturaHolcim(string $codigo, ?int $fc): array
{
    $campo = fn ($valor, $confianza = 0.95) => ['valor' => $valor, 'confianza' => $confianza];

    return lecturaUnicon([
        'proveedor' => $campo('HOLCIM PERU S.A.'),
        'ruc' => $campo('20380289360'),
        'codigo_producto' => $campo($codigo),
        'descripcion_producto' => $campo('HOLCIM CLASICO'),
        'resistencia' => $campo($fc, 0.6),
    ]);
}

function leer(Proyecto $proyecto)
{
    return test()->post('/api/v1/guias/lecturas', ['proyecto_id' => $proyecto->id, 'foto' => foto()], ['Accept' => 'application/json']);
}

function foto(): UploadedFile
{
    return UploadedFile::fake()->image('guia.jpg', 1200, 1600);
}

/** Proyecto con un almacenero asignado. */
function obraConAlmacenero(): array
{
    $proyecto = obra();

    return [$proyecto, asignadoA($proyecto, Rol::Almacenero)];
}

function datosGuia(Proyecto $proyecto, array $cambios = []): array
{
    return [
        'proyecto_id' => $proyecto->id,
        'numero' => 't001-00012345 ',
        'fecha' => today()->toDateString(),
        'resistencia_id' => resistencia(210)->id,
        'volumen_m3' => 8.5,
        'placa' => 'abc-123',
        ...$cambios,
    ];
}

describe('lectura con OCR (CU07)', function () {
    it('guarda la foto y devuelve los datos leídos con su confianza', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $unicon = Proveedor::factory()->create(['ruc' => '20100030595']);
        resistencia(210);
        ocrQueLee(lecturaUnicon());

        $respuesta = $this->actingAs($almacenero)
            ->post('/api/v1/guias/lecturas', ['proyecto_id' => $p->id, 'foto' => foto()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('lectura.proveedor_id', $unicon->id)
            ->assertJsonPath('lectura.campos.numero', ['valor' => 'T001-00012345', 'confianza' => 0.95, 'revisar' => false])
            ->assertJsonPath('lectura.campos.placa.revisar', true)
            ->assertJsonPath('lectura.campos.volumen_m3.valor', 8.5)
            ->assertJsonPath('lectura.campos.resistencia', ['valor' => 210, 'confianza' => 0.95, 'revisar' => false])
            ->assertJsonPath('lectura.resistencia_id', resistencia(210)->id);

        Storage::assertExists("guias/temporal/{$almacenero->id}/".$respuesta->json('foto_temporal'));
    });

    it('devuelve la foto sin lectura si el OCR no responde', function () {
        [$p, $almacenero] = obraConAlmacenero();
        ocrQueLee(null);

        $respuesta = $this->actingAs($almacenero)
            ->post('/api/v1/guias/lecturas', ['proyecto_id' => $p->id, 'foto' => foto()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('lectura', null)
            ->assertJsonPath('message', 'No se pudo leer la guía');

        expect($respuesta->json('foto_temporal'))->toMatch('/\.jpg$/');
    });

    it('deja vacíos y por revisar los datos que no identifica', function () {
        [$p, $almacenero] = obraConAlmacenero();
        ocrQueLee(lecturaUnicon([
            'volumen_m3' => ['valor' => null, 'confianza' => 0],
            'ruc' => ['valor' => '20100030596', 'confianza' => 0.99],
        ]));

        $this->actingAs($almacenero)
            ->post('/api/v1/guias/lecturas', ['proyecto_id' => $p->id, 'foto' => foto()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('lectura.campos.volumen_m3', ['valor' => null, 'confianza' => 0, 'revisar' => true])
            // Dígito verificador incorrecto: la confianza del modelo no basta.
            ->assertJsonPath('lectura.campos.ruc.revisar', true)
            ->assertJsonPath('lectura.proveedor_id', null);
    });

    it('toma la resistencia del producto registrado de la concretera', function () {
        [$p, $almacenero] = obraConAlmacenero();
        holcim();
        // El modelo leyó 210, pero el código del producto dice 280.
        ocrQueLee(lecturaHolcim('70280 nb5746', 210));

        $this->actingAs($almacenero);
        leer($p)->assertOk()
            ->assertJsonPath('lectura.campos.codigo_producto.valor', '70280NB5746')
            ->assertJsonPath('lectura.campos.resistencia', ['valor' => 280, 'confianza' => 1, 'revisar' => false])
            ->assertJsonPath('lectura.resistencia_id', resistencia(280)->id);
    });

    it('deduce la resistencia de un código nuevo con la regla de la concretera', function () {
        [$p, $almacenero] = obraConAlmacenero();
        holcim();
        resistencia(350);
        ocrQueLee(lecturaHolcim('70350NB5746', null));

        $this->actingAs($almacenero);
        leer($p)->assertOk()
            ->assertJsonPath('lectura.campos.resistencia', ['valor' => 350, 'confianza' => 0.9, 'revisar' => false])
            ->assertJsonPath('lectura.resistencia_id', resistencia(350)->id);
    });

    it('pide revisar una resistencia que no está en el catálogo', function () {
        [$p, $almacenero] = obraConAlmacenero();
        ocrQueLee(lecturaUnicon(['resistencia' => ['valor' => 245, 'confianza' => 0.99]]));

        $this->actingAs($almacenero);
        leer($p)->assertOk()
            ->assertJsonPath('lectura.campos.resistencia', ['valor' => null, 'confianza' => 0, 'revisar' => true])
            ->assertJsonPath('lectura.resistencia_id', null);
    });

    it('pasa al OCR las indicaciones de cada concretera', function () {
        [$p, $almacenero] = obraConAlmacenero();
        holcim();
        Proveedor::factory()->create();
        $ocr = ocrQueLee(lecturaUnicon());

        $this->actingAs($almacenero);
        leer($p)->assertOk();

        expect($ocr->indicaciones)->toBe(['HOLCIM PERU S.A. (RUC 20380289360): La resistencia está en el código del producto.']);
    });

    it('exige una imagen y acceso al proyecto', function () {
        [$p, $almacenero] = obraConAlmacenero();
        ocrQueLee(lecturaUnicon());

        $this->actingAs($almacenero)
            ->post('/api/v1/guias/lecturas', [
                'proyecto_id' => $p->id,
                'foto' => UploadedFile::fake()->create('guia.pdf', 10, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['foto' => 'Adjunte una foto JPG, PNG o WebP de hasta 10 MB']);

        $ajeno = obra(['codigo' => 'PRY-002']);
        $this->post('/api/v1/guias/lecturas', ['proyecto_id' => $ajeno->id, 'foto' => foto()], ['Accept' => 'application/json'])
            ->assertForbidden();
    });

    it('solo la usa el almacenero', function (Rol $rol) {
        $p = obra();

        $this->actingAs(asignadoA($p, $rol))
            ->post('/api/v1/guias/lecturas', ['proyecto_id' => $p->id, 'foto' => foto()], ['Accept' => 'application/json'])
            ->assertForbidden();
    })->with([Rol::Administrador, Rol::IngenieroProduccion, Rol::OficinaTecnica, Rol::Gerencia]);
});

describe('registro (CU06 y CU08)', function () {
    it('registra la guía leída con estado sin sector y mueve la foto', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $unicon = Proveedor::factory()->create(['ruc' => '20100030595']);
        ocrQueLee(lecturaUnicon());
        $temporal = $this->actingAs($almacenero)
            ->post('/api/v1/guias/lecturas', ['proyecto_id' => $p->id, 'foto' => foto()], ['Accept' => 'application/json'])
            ->json('foto_temporal');

        $this->postJson('/api/v1/guias', datosGuia($p, ['proveedor_id' => $unicon->id, 'foto_temporal' => $temporal]))
            ->assertCreated()
            ->assertJsonPath('numero', 'T001-00012345')
            ->assertJsonPath('placa', 'ABC-123')
            ->assertJsonPath('estado_asignacion', 'sin_sector')
            ->assertJsonPath('origen', 'ocr')
            ->assertJsonPath('resistencia.nombre', "f'c 210 kg/cm²")
            ->assertJsonPath('codigo_producto', null)
            ->assertJsonPath('proveedor.ruc', '20100030595');

        $guia = GuiaRemision::sole();
        expect($guia->foto_ruta)->toBe("guias/{$p->id}/{$temporal}")
            ->and($guia->registrado_por)->toBe($almacenero->id)
            ->and((float) $guia->ocr_confianza)->toBe(0.5)
            ->and($guia->ocr_payload['numero']['valor'])->toBe('T001-00012345');
        Storage::assertExists($guia->foto_ruta);
        Storage::assertMissing("guias/temporal/{$almacenero->id}/{$temporal}");
    });

    it('registra a mano con la foto adjunta', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $unicon = Proveedor::factory()->create();

        $this->actingAs($almacenero)
            ->post('/api/v1/guias', datosGuia($p, ['proveedor_id' => $unicon->id, 'placa' => '', 'foto' => foto()]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('origen', 'manual')
            ->assertJsonPath('placa', null);

        Storage::assertExists(GuiaRemision::sole()->foto_ruta);
    });

    it('registra como manual la guía cuya lectura descartó el almacenero', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $unicon = Proveedor::factory()->create(['ruc' => '20100030595']);
        ocrQueLee(lecturaUnicon());
        $temporal = $this->actingAs($almacenero)
            ->post('/api/v1/guias/lecturas', ['proyecto_id' => $p->id, 'foto' => foto()], ['Accept' => 'application/json'])
            ->json('foto_temporal');

        // Como llega del formulario multipart.
        $this->post('/api/v1/guias', datosGuia($p, ['proveedor_id' => $unicon->id, 'foto_temporal' => $temporal, 'manual' => 'true']), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('origen', 'manual');

        $guia = GuiaRemision::sole();
        expect($guia->ocr_payload)->toBeNull()
            ->and($guia->ocr_confianza)->toBeNull()
            ->and($guia->foto_ruta)->toBe("guias/{$p->id}/{$temporal}");
        Storage::assertExists($guia->foto_ruta);
    });

    it('registra el proveedor nuevo leído de la guía', function () {
        [$p, $almacenero] = obraConAlmacenero();

        $this->actingAs($almacenero)
            ->post('/api/v1/guias', datosGuia($p, [
                'proveedor_ruc' => '20100030595',
                'proveedor_razon_social' => 'UNICON S.A.',
                'foto' => foto(),
            ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('proveedor.razon_social', 'UNICON S.A.');

        expect(Proveedor::where('ruc', '20100030595')->exists())->toBeTrue();
    });

    it('valida los datos requeridos', function () {
        [$p, $almacenero] = obraConAlmacenero();

        $this->actingAs($almacenero)
            ->postJson('/api/v1/guias', ['proyecto_id' => $p->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'numero' => 'Este dato es requerido',
                'fecha' => 'Este dato es requerido',
                'resistencia_id' => 'Este dato es requerido',
                'volumen_m3' => 'Este dato es requerido',
                'proveedor_id' => 'Este dato es requerido',
                'foto' => 'Este dato es requerido',
            ])
            ->assertJsonMissingValidationErrors('placa');

        $this->postJson('/api/v1/guias', datosGuia($p, [
            'fecha' => today()->addDay()->toDateString(),
            'volumen_m3' => 0,
            'proveedor_id' => Proveedor::factory()->create()->id,
            'foto_temporal' => '../../otra-cosa.jpg',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'fecha' => 'La fecha no puede ser posterior a hoy',
                'volumen_m3' => 'Ingrese un valor mayor a cero',
                'foto_temporal' => 'La foto ya no está disponible; tómela de nuevo.',
            ]);
    });

    it('registra el producto nuevo con la resistencia confirmada y lo usa en las guías siguientes', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $holcim = holcim();

        $this->actingAs($almacenero)
            ->post('/api/v1/guias', datosGuia($p, [
                'proveedor_id' => $holcim->id,
                'codigo_producto' => ' 70210nb5746',
                'descripcion_producto' => 'HOLCIM CLASICO',
                'foto' => foto(),
            ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('codigo_producto', '70210NB5746');

        $producto = ProductoProveedor::where('codigo', '70210NB5746')->sole();
        expect($producto->proveedor_id)->toBe($holcim->id)
            ->and($producto->resistencia_id)->toBe(resistencia(210)->id)
            ->and(GuiaRemision::sole()->producto_id)->toBe($producto->id);

        ocrQueLee(lecturaHolcim('70210NB5746', null));
        leer($p)->assertJsonPath('lectura.resistencia_id', resistencia(210)->id);
    });

    it('rechaza una resistencia distinta a la del producto registrado', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $holcim = holcim();

        $this->actingAs($almacenero)
            ->post('/api/v1/guias', datosGuia($p, [
                'proveedor_id' => $holcim->id,
                'codigo_producto' => '70280NB5746',
                'foto' => foto(),
            ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resistencia_id' => "El producto 70280NB5746 está registrado como f'c 280 kg/cm²"]);

        expect(GuiaRemision::count())->toBe(0);
    });

    it('no repite el número de guía del mismo proveedor', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $unicon = Proveedor::factory()->create();
        $otro = Proveedor::factory()->create();
        $datos = fn (Proveedor $proveedor) => datosGuia($p, ['proveedor_id' => $proveedor->id, 'foto' => foto()]);

        $this->actingAs($almacenero)->post('/api/v1/guias', $datos($unicon), ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/guias', $datos($unicon), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['numero' => 'La guía ya fue registrada']);
        // Otra concretera puede usar la misma numeración.
        $this->post('/api/v1/guias', $datos($otro), ['Accept' => 'application/json'])->assertCreated();
    });

    it('no usa la foto leída por otro usuario', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $otro = asignadoA($p, Rol::Almacenero);
        ocrQueLee(lecturaUnicon());
        $temporal = $this->actingAs($almacenero)
            ->post('/api/v1/guias/lecturas', ['proyecto_id' => $p->id, 'foto' => foto()], ['Accept' => 'application/json'])
            ->json('foto_temporal');

        $this->actingAs($otro)
            ->postJson('/api/v1/guias', datosGuia($p, ['proveedor_id' => Proveedor::factory()->create()->id, 'foto_temporal' => $temporal]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['foto' => 'La foto ya no está disponible; tómela de nuevo.']);
    });

    it('no registra en un proyecto no asignado', function () {
        [, $almacenero] = obraConAlmacenero();
        $ajeno = obra(['codigo' => 'PRY-002']);

        $this->actingAs($almacenero)
            ->post('/api/v1/guias', datosGuia($ajeno, ['proveedor_id' => Proveedor::factory()->create()->id, 'foto' => foto()]), ['Accept' => 'application/json'])
            ->assertForbidden();

        expect(GuiaRemision::count())->toBe(0);
    });
});

describe('proveedores inactivos', function () {
    it('no deja registrar una guía con un proveedor inactivo', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $inactivo = Proveedor::factory()->create(['activo' => false]);

        $this->actingAs($almacenero)
            ->post('/api/v1/guias', datosGuia($p, ['proveedor_id' => $inactivo->id, 'foto' => foto()]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['proveedor_id' => 'Este proveedor está inactivo.']);

        expect(GuiaRemision::count())->toBe(0);
    });

    it('sigue mostrando el proveedor inactivo en una guía ya registrada', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $proveedor = Proveedor::factory()->create();
        $this->actingAs($almacenero)
            ->post('/api/v1/guias', datosGuia($p, ['proveedor_id' => $proveedor->id, 'foto' => foto()]), ['Accept' => 'application/json'])
            ->assertCreated();
        $proveedor->update(['activo' => false]);

        $guia = GuiaRemision::with('proveedor')->firstOrFail();
        expect(GuiaResource::make($guia)->resolve()['proveedor']->resolve())
            ->toMatchArray(['id' => $proveedor->id, 'razon_social' => $proveedor->razon_social]);
    });
});

describe('eliminar una guía registrada por error', function () {
    /** Registra una guía con foto como lo hace el almacenero y la devuelve. */
    function guiaRegistrada(Proyecto $proyecto, User $almacenero): GuiaRemision
    {
        test()->actingAs($almacenero)
            ->post('/api/v1/guias', datosGuia($proyecto, ['proveedor_id' => Proveedor::factory()->create()->id, 'foto' => foto()]), ['Accept' => 'application/json'])
            ->assertCreated();

        return GuiaRemision::latest('id')->firstOrFail();
    }

    it('elimina la guía sin sector y su foto, y deja registrarla en otro proyecto', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $correcto = obra(['codigo' => 'PRY-002']);
        $correcto->usuarios()->attach($almacenero->id);
        $guia = guiaRegistrada($p, $almacenero);

        $this->deleteJson("/api/v1/guias/{$guia->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Guía eliminada correctamente');

        expect(GuiaRemision::count())->toBe(0);
        Storage::assertMissing($guia->foto_ruta);

        $this->post('/api/v1/guias', datosGuia($correcto, ['proveedor_id' => $guia->proveedor_id, 'foto' => foto()]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('proyecto_id', $correcto->id);
    });

    it('no elimina una guía asignada', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $guia = guiaRegistrada($p, $almacenero);
        $guia->update(['estado_asignacion' => EstadoAsignacion::Asignada]);

        $this->deleteJson("/api/v1/guias/{$guia->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guia' => 'La guía ya fue asignada o facturada']);

        expect(GuiaRemision::count())->toBe(1);
        Storage::assertExists($guia->foto_ruta);
    });

    it('no elimina una guía facturada', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $guia = guiaRegistrada($p, $almacenero);
        $factura = Factura::create([
            'proyecto_id' => $p->id, 'proveedor_id' => $guia->proveedor_id, 'numero' => 'F001-00000001',
            'fecha' => today()->toDateString(), 'volumen_m3' => 8.5, 'subtotal' => 0, 'igv' => 0, 'total' => 0,
            'archivo_ruta' => "facturas/{$p->id}/f.pdf", 'registrado_por' => asignadoA($p)->id,
        ]);
        $guia->update(['factura_id' => $factura->id]);

        $this->actingAs($almacenero)
            ->deleteJson("/api/v1/guias/{$guia->id}")
            ->assertUnprocessable();

        expect(GuiaRemision::count())->toBe(1);
    });

    it('no elimina las guías de un proyecto no asignado', function () {
        [$p, $almacenero] = obraConAlmacenero();
        $guia = guiaRegistrada($p, $almacenero);
        $otro = asignadoA(obra(['codigo' => 'PRY-002']), Rol::Almacenero);

        $this->actingAs($otro)->deleteJson("/api/v1/guias/{$guia->id}")->assertForbidden();

        expect(GuiaRemision::count())->toBe(1);
    });

    it('solo la elimina el almacenero', function (Rol $rol) {
        [$p, $almacenero] = obraConAlmacenero();
        $guia = guiaRegistrada($p, $almacenero);

        $this->actingAs(asignadoA($p, $rol))->deleteJson("/api/v1/guias/{$guia->id}")->assertForbidden();
    })->with([Rol::IngenieroProduccion, Rol::OficinaTecnica, Rol::Gerencia, Rol::Administrador]);
});
