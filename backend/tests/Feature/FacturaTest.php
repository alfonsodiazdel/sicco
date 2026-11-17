<?php

use App\Contracts\OcrServiceInterface;
use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use App\Enums\Rol;
use App\Models\Factura;
use App\Models\FacturaCargo;
use App\Models\FacturaEliminada;
use App\Models\FacturaLinea;
use App\Models\GuiaRemision;
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

/** Proyecto con una concretera y un usuario de Oficina técnica asignado. */
function obraConOficina(): array
{
    $proyecto = obra();

    return [$proyecto, Proveedor::factory()->create(), asignadoA($proyecto, Rol::OficinaTecnica)];
}

function guiaPorFacturar(Proyecto $proyecto, Proveedor $proveedor, float $volumen, int $fc = 280, array $atributos = []): GuiaRemision
{
    static $numero = 0;
    $numero++;

    return GuiaRemision::create([
        'proyecto_id' => $proyecto->id,
        'proveedor_id' => $proveedor->id,
        'numero' => sprintf('T001-%08d', $numero),
        'fecha' => today()->subDays(2)->toDateString(),
        'resistencia_id' => resistencia($fc)->id,
        'volumen_m3' => $volumen,
        'foto_ruta' => "guias/{$proyecto->id}/foto.jpg",
        'estado_asignacion' => EstadoAsignacion::SinSector,
        'origen' => OrigenGuia::Manual,
        'registrado_por' => User::factory()->rol(Rol::Almacenero)->create()->id,
        ...$atributos,
    ]);
}

/** Factura como la F001-2803 del proyecto Larco: f'c 280 a S/ 291 y f'c 350 a S/ 329. */
function datosFactura(Proyecto $proyecto, Proveedor $proveedor, array $guias, array $cambios = []): array
{
    return [
        'proyecto_id' => $proyecto->id,
        'proveedor_id' => $proveedor->id,
        'numero' => 'f001-00002803 ',
        'fecha' => today()->toDateString(),
        'archivo' => UploadedFile::fake()->create('factura.pdf', 200, 'application/pdf'),
        'lineas' => [
            ['resistencia_id' => resistencia(280)->id, 'volumen_m3' => 8.5, 'precio_unitario' => 291],
            ['resistencia_id' => resistencia(350)->id, 'volumen_m3' => 14.5, 'precio_unitario' => 329],
        ],
        'igv' => 1303.92,
        'guia_ids' => array_map(fn (GuiaRemision $g) => $g->id, $guias),
        ...$cambios,
    ];
}

function registrarFactura(array $datos)
{
    return test()->post('/api/v1/facturas', $datos, ['Accept' => 'application/json']);
}

/** Las tres guías que cobra la factura del ejemplo: 8.5 m³ de f'c 280 y 7.5 + 7 m³ de f'c 350. */
function guiasDelEjemplo(Proyecto $proyecto, Proveedor $proveedor): array
{
    return [
        guiaPorFacturar($proyecto, $proveedor, 8.5, 280),
        guiaPorFacturar($proyecto, $proveedor, 7.5, 350),
        guiaPorFacturar($proyecto, $proveedor, 7, 350),
    ];
}

describe('guías por facturar (CU12 paso 1)', function () {
    it('lista las guías del proveedor y proyecto que aún no están facturadas', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $reciente = guiaPorFacturar($p, $proveedor, 8, atributos: ['fecha' => today()->toDateString()]);
        $antigua = guiaPorFacturar($p, $proveedor, 7, atributos: ['fecha' => today()->subDays(5)->toDateString()]);
        guiaPorFacturar($p, Proveedor::factory()->create(), 8);
        guiaPorFacturar(obra(['codigo' => 'PRY-002']), $proveedor, 8);
        $facturada = guiaPorFacturar($p, $proveedor, 6);
        $this->actingAs($oficina);
        registrarFactura(datosFactura($p, $proveedor, [$facturada], [
            'lineas' => [['resistencia_id' => resistencia(280)->id, 'volumen_m3' => 6, 'precio_unitario' => 291]],
            'igv' => 314.28,
        ]));

        $this->actingAs($oficina)
            ->getJson("/api/v1/proyectos/{$p->id}/guias/por-facturar?proveedor_id={$proveedor->id}")
            ->assertOk()
            ->assertJsonPath('*.id', [$antigua->id, $reciente->id])
            ->assertJsonPath('0.resistencia.fc_kg_cm2', 280);
    });

    it('devuelve una lista vacía si no hay guías pendientes del proveedor', function () {
        [$p, $proveedor, $oficina] = obraConOficina();

        $this->actingAs($oficina)
            ->getJson("/api/v1/proyectos/{$p->id}/guias/por-facturar?proveedor_id={$proveedor->id}")
            ->assertOk()
            ->assertJsonCount(0);
    });

    it('exige el proveedor', function () {
        [$p, , $oficina] = obraConOficina();

        $this->actingAs($oficina)
            ->getJson("/api/v1/proyectos/{$p->id}/guias/por-facturar")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['proveedor_id' => 'Este dato es requerido']);
    });
});

describe('registro de la factura (CU11)', function () {
    it('registra la factura, su detalle y vincula las guías como facturadas', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $guias = guiasDelEjemplo($p, $proveedor);

        $this->actingAs($oficina);
        $respuesta = registrarFactura(datosFactura($p, $proveedor, $guias))
            ->assertCreated()
            ->assertJsonPath('message', 'Factura registrada correctamente')
            ->assertJsonPath('data.numero', 'F001-00002803')
            ->assertJsonPath('data.volumen_m3', 23)
            ->assertJsonPath('data.subtotal', 7244)
            ->assertJsonPath('data.igv', 1303.92)
            ->assertJsonPath('data.total', 8547.92)
            ->assertJsonPath('data.diferencia_confirmada', false)
            ->assertJsonPath('data.guias_count', 3);

        $factura = Factura::findOrFail($respuesta->json('data.id'));
        expect($factura->lineas)->toHaveCount(2)
            ->and($factura->lineas->firstWhere('resistencia_id', resistencia(350)->id)->importe)->toBe('4770.50')
            ->and($factura->guias->modelKeys())->toEqualCanonicalizing(array_map(fn ($g) => $g->id, $guias))
            ->and($factura->registrado_por)->toBe($oficina->id);
        Storage::assertExists($factura->archivo_ruta);
        expect($factura->archivo_ruta)->toStartWith("facturas/{$p->id}/")->toEndWith('.pdf');
    });

    it('muestra la factura con sus guías y el importe de cada una', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $guias = guiasDelEjemplo($p, $proveedor);

        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, $guias))->json('data.id');

        $this->getJson("/api/v1/facturas/{$id}")
            ->assertOk()
            ->assertJsonCount(2, 'lineas')
            ->assertJsonPath('guias.0.id', $guias[0]->id)
            // 8.5 m³ × S/ 291 y 7.5 m³ × S/ 329.
            ->assertJsonPath('guias.0.importe', 2473.5)
            ->assertJsonPath('guias.1.importe', 2467.5)
            ->assertJsonPath('guias.0.proveedor.id', $proveedor->id);
    });

    it('lista las facturas del proyecto, de la más reciente a la más antigua', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $this->actingAs($oficina);
        $una = guiaPorFacturar($p, $proveedor, 6);
        $otra = guiaPorFacturar($p, $proveedor, 6);
        $linea = ['lineas' => [['resistencia_id' => resistencia(280)->id, 'volumen_m3' => 6, 'precio_unitario' => 291]], 'igv' => 314.28];
        registrarFactura(datosFactura($p, $proveedor, [$una], [...$linea, 'numero' => 'F001-1', 'fecha' => today()->subDays(3)->toDateString()]));
        registrarFactura(datosFactura($p, $proveedor, [$otra], [...$linea, 'numero' => 'F001-2']));

        $this->getJson("/api/v1/proyectos/{$p->id}/facturas")
            ->assertOk()
            ->assertJsonPath('*.numero', ['F001-00000002', 'F001-00000001'])
            ->assertJsonPath('0.guias_count', 1)
            ->assertJsonPath('0.proveedor.id', $proveedor->id);
    });

    it('pide confirmar si el volumen facturado no coincide con las guías', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        // Falta la guía de 7 m³ de f'c 350.
        $guias = array_slice(guiasDelEjemplo($p, $proveedor), 0, 2);

        $this->actingAs($oficina);
        registrarFactura(datosFactura($p, $proveedor, $guias))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'requiere_confirmacion')
            ->assertJsonValidationErrors(['guia_ids' => 'El volumen facturado no coincide con las guías']);
        expect(Factura::count())->toBe(0);

        registrarFactura(datosFactura($p, $proveedor, $guias, ['confirmado' => '1']))
            ->assertCreated()
            ->assertJsonPath('data.diferencia_confirmada', true);
    });

    it('compara el volumen por resistencia y no solo el total', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        // 23 m³ en total, igual que la factura, pero todo de f'c 280.
        $guias = [guiaPorFacturar($p, $proveedor, 8.5, 280), guiaPorFacturar($p, $proveedor, 14.5, 280)];

        $this->actingAs($oficina);
        registrarFactura(datosFactura($p, $proveedor, $guias))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'requiere_confirmacion');
    });

    it('no repite el número de factura del mismo proveedor', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $this->actingAs($oficina);
        registrarFactura(datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor)))->assertCreated();

        registrarFactura(datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor), ['numero' => 'F001-00002803']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['numero' => 'La factura ya fue registrada']);

        // Otro proveedor puede usar la misma numeración.
        $otro = Proveedor::factory()->create();
        registrarFactura(datosFactura($p, $otro, guiasDelEjemplo($p, $otro)))->assertCreated();
    });

    it('no vincula una guía ya facturada, de otro proveedor o de otro proyecto', function (string $caso) {
        [$p, $proveedor, $oficina] = obraConOficina();
        $this->actingAs($oficina);
        $guias = guiasDelEjemplo($p, $proveedor);
        $ajena = match ($caso) {
            'facturada' => $guias[2],
            'proveedor' => guiaPorFacturar($p, Proveedor::factory()->create(), 7, 350),
            'proyecto' => guiaPorFacturar(obra(['codigo' => 'PRY-002']), $proveedor, 7, 350),
        };
        if ($caso === 'facturada') {
            registrarFactura(datosFactura($p, $proveedor, [$guias[2]], [
                'numero' => 'F001-1',
                'lineas' => [['resistencia_id' => resistencia(350)->id, 'volumen_m3' => 7, 'precio_unitario' => 329]],
                'igv' => 414.54,
            ]))->assertCreated();
        }

        registrarFactura(datosFactura($p, $proveedor, [$guias[0], $guias[1], $ajena]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guia_ids.2' => "La guía {$ajena->numero} ya está facturada o no es del proveedor y proyecto"]);
        expect($guias[0]->refresh()->factura_id)->toBeNull();
    })->with(['facturada', 'proveedor', 'proyecto']);

    it('valida los datos requeridos', function () {
        [$p, , $oficina] = obraConOficina();

        $this->actingAs($oficina);
        registrarFactura(['proyecto_id' => $p->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'proveedor_id' => 'Este dato es requerido',
                'numero' => 'Este dato es requerido',
                'fecha' => 'Este dato es requerido',
                'archivo' => 'Este dato es requerido',
                'lineas' => 'Agregue al menos una resistencia',
                'igv' => 'Este dato es requerido',
                'guia_ids' => 'Vincule al menos una guía',
            ]);
    });

    it('valida el archivo, los montos y las resistencias', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $guias = guiasDelEjemplo($p, $proveedor);

        $this->actingAs($oficina);
        registrarFactura(datosFactura($p, $proveedor, $guias, [
            'archivo' => UploadedFile::fake()->create('factura.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'fecha' => today()->addDay()->toDateString(),
            'lineas' => [
                ['resistencia_id' => resistencia(280)->id, 'volumen_m3' => 8.555, 'precio_unitario' => 0],
                ['resistencia_id' => resistencia(280)->id, 'volumen_m3' => 14.5, 'precio_unitario' => 329],
            ],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'archivo' => 'Adjunte una imagen (JPG, PNG o WebP) o un PDF de hasta 10 MB',
                'fecha' => 'La fecha no puede ser posterior a hoy',
                'lineas.0.volumen_m3' => 'Ingrese como máximo dos decimales',
                'lineas.0.precio_unitario' => 'Ingrese un valor mayor a cero',
                'lineas.1.resistencia_id' => 'La resistencia ya está en la factura',
            ]);
    });

    it('acepta la factura como imagen', function () {
        [$p, $proveedor, $oficina] = obraConOficina();

        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor), [
            'archivo' => UploadedFile::fake()->image('factura.png', 800, 1100),
        ]))->assertCreated()->json('data.id');

        expect(Factura::find($id)->archivo_ruta)->toEndWith('.png');
    });

    it('redondea el importe de cada línea al céntimo', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $guia = guiaPorFacturar($p, $proveedor, 8.33, 280);

        $this->actingAs($oficina);
        // 8.33 × 291.35 = 2426.9455 → 2426.95
        registrarFactura(datosFactura($p, $proveedor, [$guia], [
            'lineas' => [['resistencia_id' => resistencia(280)->id, 'volumen_m3' => 8.33, 'precio_unitario' => 291.35]],
            'igv' => 436.85,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 2426.95)
            ->assertJsonPath('data.total', 2863.8);
    });
});

function actualizarGuias(int $facturaId, array $guias, array $cambios = [])
{
    return test()->putJson("/api/v1/facturas/{$facturaId}/guias", [
        'guia_ids' => array_map(fn (GuiaRemision $g) => $g->id, $guias),
        ...$cambios,
    ]);
}

describe('guías de una factura ya registrada (CU12)', function () {
    it('agrega una guía que llegó después y deja de pedir confirmación', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        [$a, $b, $tardia] = guiasDelEjemplo($p, $proveedor);
        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, [$a, $b], ['confirmado' => '1']))
            ->assertJsonPath('data.diferencia_confirmada', true)
            ->json('data.id');

        actualizarGuias($id, [$a, $b, $tardia])
            ->assertOk()
            ->assertJsonPath('message', 'Guías de la factura actualizadas correctamente')
            ->assertJsonPath('data.diferencia_confirmada', false)
            ->assertJsonPath('data.guias_count', 3)
            ->assertJsonPath('data.guias.2.id', $tardia->id)
            ->assertJsonPath('data.guias.2.importe', 2303);

        expect($tardia->refresh()->factura_id)->toBe($id);
        $this->getJson("/api/v1/proyectos/{$p->id}/guias/por-facturar?proveedor_id={$proveedor->id}")
            ->assertJsonCount(0);
    });

    it('quita una guía equivocada, que vuelve a quedar por facturar', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $guias = guiasDelEjemplo($p, $proveedor);
        $equivocada = guiaPorFacturar($p, $proveedor, 6, 280);
        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, [...$guias, $equivocada], ['confirmado' => '1']))->json('data.id');

        actualizarGuias($id, $guias)
            ->assertOk()
            ->assertJsonPath('data.diferencia_confirmada', false)
            ->assertJsonPath('data.guias_count', 3);

        expect($equivocada->refresh()->factura_id)->toBeNull();
        $this->getJson("/api/v1/proyectos/{$p->id}/guias/por-facturar?proveedor_id={$proveedor->id}")
            ->assertJsonPath('*.id', [$equivocada->id]);
    });

    it('pide confirmar si el volumen no coincide y no cambia nada hasta confirmar', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $guias = guiasDelEjemplo($p, $proveedor);
        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, $guias))->json('data.id');

        actualizarGuias($id, array_slice($guias, 0, 2))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'requiere_confirmacion');
        expect($guias[2]->refresh()->factura_id)->toBe($id);

        actualizarGuias($id, array_slice($guias, 0, 2), ['confirmado' => true])
            ->assertOk()
            ->assertJsonPath('data.diferencia_confirmada', true);
        expect($guias[2]->refresh()->factura_id)->toBeNull();
    });

    it('no toma una guía de otra factura, de otro proveedor o de otro proyecto', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $guias = guiasDelEjemplo($p, $proveedor);
        $otraGuia = guiaPorFacturar($p, $proveedor, 6);
        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, $guias))->json('data.id');
        registrarFactura(datosFactura($p, $proveedor, [$otraGuia], [
            'numero' => 'F001-9',
            'lineas' => [['resistencia_id' => resistencia(280)->id, 'volumen_m3' => 6, 'precio_unitario' => 291]],
            'igv' => 314.28,
        ]))->assertCreated();

        $ajenas = [
            $otraGuia,
            guiaPorFacturar($p, Proveedor::factory()->create(), 6),
            guiaPorFacturar(obra(['codigo' => 'PRY-002']), $proveedor, 6),
        ];
        foreach ($ajenas as $ajena) {
            actualizarGuias($id, [...$guias, $ajena], ['confirmado' => true])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['guia_ids.3']);
        }
        expect($otraGuia->refresh()->factura->numero)->toBe('F001-00000009');
    });

    it('exige al menos una guía', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor)))->json('data.id');

        actualizarGuias($id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guia_ids' => 'Vincule al menos una guía']);
    });

    it('solo las cambia Oficina técnica de un proyecto asignado', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $guias = guiasDelEjemplo($p, $proveedor);
        $id = $this->actingAs($oficina)->post('/api/v1/facturas', datosFactura($p, $proveedor, $guias), ['Accept' => 'application/json'])
            ->json('data.id');

        $this->actingAs(conRol(Rol::Administrador));
        actualizarGuias($id, $guias)->assertForbidden();
        $this->actingAs(asignadoA(obra(['codigo' => 'PRY-003'])));
        actualizarGuias($id, $guias)->assertForbidden();
    });
});

describe('eliminar una factura registrada por error', function () {
    it('la elimina con su detalle y archivo, libera las guías y deja constancia', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $guias = guiasDelEjemplo($p, $proveedor);
        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, $guias, [
            'cargos' => [['descripcion' => 'Bombeo', 'importe' => 450]],
        ]))->assertCreated()->json('data.id');
        $ruta = Factura::findOrFail($id)->archivo_ruta;

        $this->deleteJson("/api/v1/facturas/{$id}", ['motivo' => '  Era del proyecto Surco  '])
            ->assertOk()
            ->assertJsonPath('message', 'Factura eliminada correctamente');

        expect(Factura::count())->toBe(0)
            ->and(FacturaLinea::count())->toBe(0)
            ->and(FacturaCargo::count())->toBe(0)
            ->and(GuiaRemision::whereNotNull('factura_id')->count())->toBe(0);
        Storage::assertMissing($ruta);
        $this->getJson("/api/v1/proyectos/{$p->id}/guias/por-facturar?proveedor_id={$proveedor->id}")
            ->assertJsonCount(3);

        $log = FacturaEliminada::sole();
        expect($log->factura_id)->toBe($id)
            ->and($log->proyecto_id)->toBe($p->id)
            ->and($log->proveedor_id)->toBe($proveedor->id)
            ->and($log->numero)->toBe('F001-00002803')
            ->and($log->total)->toBe('8997.92')
            ->and($log->motivo)->toBe('Era del proyecto Surco')
            ->and($log->registrado_por)->toBe($oficina->id)
            ->and($log->eliminado_por)->toBe($oficina->id)
            ->and($log->datos['lineas'])->toHaveCount(2)
            ->and($log->datos['cargos'])->toEqual([['descripcion' => 'Bombeo', 'importe' => '450.00']])
            ->and(array_column($log->datos['guias'], 'id'))->toBe(array_map(fn ($g) => $g->id, $guias))
            ->and($log->datos['factura']['archivo_ruta'])->toBe($ruta);
    });

    it('deja registrarla de nuevo en el proyecto correcto con el mismo número', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $correcto = obra(['codigo' => 'PRY-002']);
        $correcto->usuarios()->attach($oficina->id);
        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor)))->json('data.id');

        registrarFactura(datosFactura($correcto, $proveedor, guiasDelEjemplo($correcto, $proveedor)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['numero' => 'La factura ya fue registrada']);

        $this->deleteJson("/api/v1/facturas/{$id}")->assertOk();
        expect(FacturaEliminada::sole()->motivo)->toBeNull();

        registrarFactura(datosFactura($correcto, $proveedor, guiasDelEjemplo($correcto, $proveedor)))
            ->assertCreated()
            ->assertJsonPath('data.numero', 'F001-00002803');
    });

    it('valida el motivo', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor)))->json('data.id');

        $this->deleteJson("/api/v1/facturas/{$id}", ['motivo' => str_repeat('a', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['motivo' => 'Debe tener como máximo 500 caracteres']);

        expect(Factura::count())->toBe(1)->and(FacturaEliminada::count())->toBe(0);
    });

    it('solo la elimina Oficina técnica de un proyecto asignado', function (?Rol $rol) {
        [$p, $proveedor, $oficina] = obraConOficina();
        $id = $this->actingAs($oficina)
            ->post('/api/v1/facturas', datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor)), ['Accept' => 'application/json'])
            ->json('data.id');

        $usuario = $rol ? asignadoA($p, $rol) : asignadoA(obra(['codigo' => 'PRY-003']));
        $this->actingAs($usuario)->deleteJson("/api/v1/facturas/{$id}")->assertForbidden();

        expect(Factura::count())->toBe(1)
            ->and(GuiaRemision::whereNotNull('factura_id')->count())->toBe(3)
            ->and(FacturaEliminada::count())->toBe(0);
    })->with([
        'administrador' => Rol::Administrador,
        'almacenero' => Rol::Almacenero,
        'producción' => Rol::IngenieroProduccion,
        'gerencia' => Rol::Gerencia,
        'oficina técnica de otro proyecto' => null,
    ]);
});

describe('aviso de lo que falta por vincular', function () {
    it('26 m³ facturados con 6 vinculados: faltan 20, y desaparece al vincular el resto', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $primera = guiaPorFacturar($p, $proveedor, 6, 210);
        $this->actingAs($oficina);
        $id = registrarFactura(datosFactura($p, $proveedor, [$primera], [
            'lineas' => [
                ['resistencia_id' => resistencia(210)->id, 'volumen_m3' => 17, 'precio_unitario' => 300],
                ['resistencia_id' => resistencia(280)->id, 'volumen_m3' => 9, 'precio_unitario' => 320],
            ],
            'igv' => 1436.4,
            'confirmado' => '1',
        ]))->assertCreated()
            ->assertJsonPath('data.volumen_pendiente_m3', 20)
            ->json('data.id');

        $this->getJson("/api/v1/facturas/{$id}")
            ->assertJsonPath('volumen_m3', 26)
            ->assertJsonPath('volumen_guias_m3', 6)
            ->assertJsonPath('volumen_pendiente_m3', 20)
            ->assertJsonCount(2, 'pendiente_por_resistencia')
            ->assertJsonPath('pendiente_por_resistencia.0.resistencia.fc_kg_cm2', 210)
            ->assertJsonPath('pendiente_por_resistencia.0.faltan_m3', 11)
            ->assertJsonPath('pendiente_por_resistencia.1.resistencia.fc_kg_cm2', 280)
            ->assertJsonPath('pendiente_por_resistencia.1.faltan_m3', 9);
        $this->getJson("/api/v1/proyectos/{$p->id}/facturas")
            ->assertJsonPath('0.volumen_guias_m3', 6)
            ->assertJsonPath('0.volumen_pendiente_m3', 20)
            ->assertJsonPath('0.guias_count', 1);

        actualizarGuias($id, [$primera, guiaPorFacturar($p, $proveedor, 11, 210), guiaPorFacturar($p, $proveedor, 9, 280)])
            ->assertOk()
            ->assertJsonPath('data.volumen_guias_m3', 26)
            ->assertJsonPath('data.volumen_pendiente_m3', 0)
            ->assertJsonPath('data.pendiente_por_resistencia', []);
        $this->getJson("/api/v1/proyectos/{$p->id}/facturas")->assertJsonPath('0.volumen_pendiente_m3', 0);
    });
});

describe('acceso', function () {
    it('solo registra Oficina técnica', function (Rol $rol) {
        [$p, $proveedor] = obraConOficina();
        $guias = guiasDelEjemplo($p, $proveedor);

        $this->actingAs(asignadoA($p, $rol));
        registrarFactura(datosFactura($p, $proveedor, $guias))->assertForbidden();
        $this->getJson("/api/v1/proyectos/{$p->id}/guias/por-facturar?proveedor_id={$proveedor->id}")->assertForbidden();
    })->with([Rol::Administrador, Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia]);

    it('el administrador consulta las facturas y su archivo', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $id = $this->actingAs($oficina)->post('/api/v1/facturas', datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor)), ['Accept' => 'application/json'])
            ->json('data.id');

        $this->actingAs(conRol(Rol::Administrador));
        $this->getJson("/api/v1/proyectos/{$p->id}/facturas")->assertOk()->assertJsonCount(1);
        $this->getJson("/api/v1/facturas/{$id}")->assertOk();
        $this->get("/api/v1/facturas/{$id}/archivo")->assertOk();
    });

    it('no las consultan los demás roles', function (Rol $rol) {
        [$p] = obraConOficina();

        $this->actingAs(asignadoA($p, $rol))
            ->getJson("/api/v1/proyectos/{$p->id}/facturas")
            ->assertForbidden();
    })->with([Rol::Almacenero, Rol::IngenieroProduccion, Rol::Gerencia]);

    it('no registra ni muestra facturas de un proyecto no asignado', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $ajeno = obra(['codigo' => 'PRY-002']);
        $guias = guiasDelEjemplo($ajeno, $proveedor);

        $this->actingAs($oficina);
        registrarFactura(datosFactura($ajeno, $proveedor, $guias))->assertForbidden();
        $this->getJson("/api/v1/proyectos/{$ajeno->id}/facturas")->assertForbidden();

        $id = $this->actingAs(asignadoA($ajeno))->post('/api/v1/facturas', datosFactura($ajeno, $proveedor, $guias), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->actingAs($oficina);
        $this->getJson("/api/v1/facturas/{$id}")->assertForbidden();
        $this->get("/api/v1/facturas/{$id}/archivo", ['Accept' => 'application/json'])->assertForbidden();
    });
});

/** OCR que devuelve la factura dada, o falla si es null. */
function ocrDeFactura(?array $datos): void
{
    app()->instance(OcrServiceInterface::class, new class($datos) implements OcrServiceInterface
    {
        public function __construct(private ?array $datos) {}

        public function leerGuia(string $imagen, string $mediaType, array $indicaciones = []): LecturaGuia
        {
            throw new LecturaNoDisponible('No se usa en las facturas');
        }

        public function leerFactura(string $archivo, string $mediaType, array $indicaciones = []): LecturaFactura
        {
            return $this->datos === null
                ? throw new LecturaNoDisponible('Sin respuesta')
                : LecturaFactura::desdeDatos($this->datos, ['modelo' => 'prueba']);
        }
    });
}

/** Holcim con su regla de código (70280… es f'c 280), como la registra ConcreteraSeeder. */
function holcimFacturas(): Proveedor
{
    $holcim = Proveedor::create(['ruc' => '20380289360', 'razon_social' => 'HOLCIM PERU S.A.']);
    $holcim->forceFill(['patron_codigo' => '^70(?<fc>\d{3})'])->save();

    return $holcim;
}

/** Lo que lee el OCR de la F012-180490 de Holcim, más un cargo de bombeo. */
function lecturaFacturaHolcim(array $cambios = []): array
{
    $campo = fn ($valor, float $confianza = 0.97) => ['valor' => $valor, 'confianza' => $confianza];

    return [
        'es_factura' => true,
        'numero' => $campo('F012-180490'),
        'fecha' => $campo(today()->subDays(3)->toDateString().' 12:00:00'),
        'proveedor' => $campo('HOLCIM PERU S.A.'),
        'ruc' => $campo('20380289360'),
        'subtotal' => $campo(4609),
        'igv' => $campo(829.62),
        'total' => $campo(5438.62),
        'items' => [
            ['descripcion' => 'HOLCIM CLASICO', 'codigo_producto' => '70280NB5746', 'resistencia' => null, 'es_concreto' => true,
                'cantidad' => 13, 'valor_unitario' => 293, 'importe' => 3809, 'confianza' => 0.95],
            ['descripcion' => 'SERVICIO DE BOMBEO', 'codigo_producto' => null, 'resistencia' => null, 'es_concreto' => false,
                'cantidad' => 20, 'valor_unitario' => 40, 'importe' => 800, 'confianza' => 0.95],
        ],
        'guias' => ['T006-00012289', 't006-12292'],
        ...$cambios,
    ];
}

function leerFacturaDe(Proyecto $proyecto, ?UploadedFile $archivo = null)
{
    return test()->post('/api/v1/facturas/lecturas', [
        'proyecto_id' => $proyecto->id,
        'archivo' => $archivo ?? UploadedFile::fake()->create('F012-180490.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json']);
}

describe('lectura con OCR', function () {
    it('lee la factura: cabecera, concreto con su resistencia, otros cargos y guías citadas', function () {
        [$p, , $oficina] = obraConOficina();
        $holcim = holcimFacturas();
        $citada = guiaPorFacturar($p, $holcim, 6.5, 280, ['numero' => 'T006-00012289']);
        $otra = guiaPorFacturar($p, $holcim, 6.5, 280, ['numero' => 'T006-00012292']);
        ocrDeFactura(lecturaFacturaHolcim());

        $this->actingAs($oficina);
        $respuesta = leerFacturaDe($p)->assertOk()
            ->assertJsonPath('lectura.proveedor_id', $holcim->id)
            ->assertJsonPath('lectura.campos.numero.valor', 'F012-00180490')
            ->assertJsonPath('lectura.campos.fecha.valor', today()->subDays(3)->toDateString())
            ->assertJsonPath('lectura.campos.total.valor', 5438.62)
            ->assertJsonPath('lectura.campos.total.revisar', false)
            // La resistencia sale del código con la regla de Holcim.
            ->assertJsonPath('lectura.lineas.0.resistencia_id', resistencia(280)->id)
            ->assertJsonPath('lectura.lineas.0.volumen_m3', 13)
            ->assertJsonPath('lectura.lineas.0.precio_unitario', 293)
            ->assertJsonPath('lectura.cargos.0.descripcion', 'SERVICIO DE BOMBEO')
            ->assertJsonPath('lectura.cargos.0.importe', 800)
            ->assertJsonPath('lectura.guias.sugeridas', [$citada->id, $otra->id])
            ->assertJsonPath('lectura.guias.avisos', []);

        Storage::assertExists("facturas/temporal/{$oficina->id}/".$respuesta->json('archivo_temporal'));
        expect($respuesta->json('archivo_temporal'))->toEndWith('.pdf');
    });

    it('avisa de las guías citadas que no puede proponer', function () {
        [$p, , $oficina] = obraConOficina();
        $holcim = holcimFacturas();
        guiaPorFacturar(obra(['codigo' => 'PRY-002']), $holcim, 6.5, 280, ['numero' => 'T006-00012289']);
        ocrDeFactura(lecturaFacturaHolcim());

        $this->actingAs($oficina);
        leerFacturaDe($p)->assertOk()
            ->assertJsonPath('lectura.guias.sugeridas', [])
            ->assertJsonPath('lectura.guias.avisos', [
                'La factura cita la guía T006-00012289, que es de otro proyecto',
                'La factura cita la guía T006-00012292, que no está registrada',
            ]);
    });

    it('marca para revisar lo que no cuadra', function () {
        [$p, , $oficina] = obraConOficina();
        holcimFacturas();
        $lectura = lecturaFacturaHolcim(['total' => ['valor' => 5500, 'confianza' => 0.99]]);
        // 13 × 293 no da 3900.
        $lectura['items'][0]['importe'] = 3900;
        ocrDeFactura($lectura);

        $this->actingAs($oficina);
        leerFacturaDe($p)->assertOk()
            ->assertJsonPath('lectura.campos.total.revisar', true)
            ->assertJsonPath('lectura.campos.subtotal.revisar', true)
            ->assertJsonPath('lectura.lineas.0.revisar', true);
    });

    it('no propone proveedor si el RUC no está registrado', function () {
        [$p, , $oficina] = obraConOficina();
        ocrDeFactura(lecturaFacturaHolcim());

        $this->actingAs($oficina);
        leerFacturaDe($p)->assertOk()
            ->assertJsonPath('lectura.proveedor_id', null)
            ->assertJsonPath('lectura.lineas.0.resistencia_id', null)
            ->assertJsonPath('lectura.lineas.0.revisar', true);
    });

    it('devuelve el archivo sin lectura si el OCR no responde', function () {
        [$p, , $oficina] = obraConOficina();
        ocrDeFactura(null);

        $this->actingAs($oficina);
        leerFacturaDe($p)->assertOk()
            ->assertJsonPath('lectura', null)
            ->assertJsonPath('message', 'No se pudo leer la factura');
    });

    it('solo la usa Oficina técnica en sus proyectos', function () {
        [$p] = obraConOficina();
        ocrDeFactura(lecturaFacturaHolcim());

        $this->actingAs(asignadoA($p, Rol::IngenieroProduccion));
        leerFacturaDe($p)->assertForbidden();
        $this->actingAs(asignadoA(obra(['codigo' => 'PRY-002'])));
        leerFacturaDe($p)->assertForbidden();
    });

    it('registra la factura leída sin volver a subir el archivo y guarda la lectura', function () {
        [$p, , $oficina] = obraConOficina();
        $holcim = holcimFacturas();
        $guias = [
            guiaPorFacturar($p, $holcim, 6.5, 280, ['numero' => 'T006-00012289']),
            guiaPorFacturar($p, $holcim, 6.5, 280, ['numero' => 'T006-00012292']),
        ];
        ocrDeFactura(lecturaFacturaHolcim());
        $this->actingAs($oficina);
        $temporal = leerFacturaDe($p)->json('archivo_temporal');

        $id = registrarFactura([
            ...datosFactura($p, $holcim, $guias, [
                'numero' => 'F012-180490',
                'lineas' => [['resistencia_id' => resistencia(280)->id, 'volumen_m3' => 13, 'precio_unitario' => 293]],
                'cargos' => [['descripcion' => 'SERVICIO DE BOMBEO', 'importe' => 800]],
                'igv' => 829.62,
            ]),
            'archivo' => null,
            'archivo_temporal' => $temporal,
        ])
            ->assertCreated()
            ->assertJsonPath('data.numero', 'F012-00180490')
            // El bombeo suma al costo, no al volumen.
            ->assertJsonPath('data.volumen_m3', 13)
            ->assertJsonPath('data.subtotal', 4609)
            ->assertJsonPath('data.total', 5438.62)
            ->assertJsonPath('data.diferencia_confirmada', false)
            ->assertJsonPath('data.origen', 'ocr')
            ->json('data.id');

        $factura = Factura::findOrFail($id);
        expect($factura->ocr_payload['modelo'])->toBe('prueba')
            ->and($factura->cargos)->toHaveCount(1)
            ->and($factura->archivo_ruta)->toBe("facturas/{$p->id}/{$temporal}");
        Storage::assertExists($factura->archivo_ruta);
        Storage::assertMissing("facturas/temporal/{$oficina->id}/{$temporal}");

        $this->getJson("/api/v1/facturas/{$id}")->assertJsonPath('cargos.0.importe', 800);
    });

    it('no usa el archivo leído por otro usuario', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        ocrDeFactura(lecturaFacturaHolcim());
        $temporal = $this->actingAs($oficina)->post('/api/v1/facturas/lecturas', [
            'proyecto_id' => $p->id,
            'archivo' => UploadedFile::fake()->create('f.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->json('archivo_temporal');

        $this->actingAs(asignadoA($p));
        registrarFactura([...datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor)), 'archivo' => null, 'archivo_temporal' => $temporal])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['archivo' => 'El archivo ya no está disponible; adjúntelo de nuevo.']);
    });

    it('trata como la misma factura el número con o sin ceros', function () {
        [$p, $proveedor, $oficina] = obraConOficina();
        $this->actingAs($oficina);
        registrarFactura(datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor), ['numero' => 'F001-5228']))->assertCreated();

        registrarFactura(datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor), ['numero' => 'F001-00005228']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['numero' => 'La factura ya fue registrada']);
    });

    it('valida los otros cargos', function () {
        [$p, $proveedor, $oficina] = obraConOficina();

        $this->actingAs($oficina);
        registrarFactura(datosFactura($p, $proveedor, guiasDelEjemplo($p, $proveedor), [
            'cargos' => [['descripcion' => '', 'importe' => 0]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'cargos.0.descripcion' => 'Este dato es requerido',
                'cargos.0.importe' => 'Ingrese un valor mayor a cero',
            ]);
    });
});

it('no deja registrar una factura con un proveedor inactivo', function () {
    [$p, $proveedor, $oficina] = obraConOficina();
    $proveedor->update(['activo' => false]);

    $this->actingAs($oficina);
    registrarFactura(datosFactura($p, $proveedor, []))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['proveedor_id' => 'Este proveedor está inactivo.']);
});
