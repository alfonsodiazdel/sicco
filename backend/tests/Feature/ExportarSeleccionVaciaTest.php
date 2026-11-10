<?php

use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use App\Enums\Rol;
use App\Models\GuiaRemision;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Documentos\ArmadorZip;
use Illuminate\Support\Facades\Storage;

/** Un proyecto con una guía del proveedor dado y su foto en el disco. */
function proyectoConGuiaDe(Proveedor $proveedor): Proyecto
{
    $proyecto = obra();
    Storage::put("guias/{$proyecto->id}/a.jpg", 'foto-a');
    GuiaRemision::create([
        'proyecto_id' => $proyecto->id,
        'proveedor_id' => $proveedor->id,
        'numero' => 'T001-00000101',
        'fecha' => '2026-11-05',
        'resistencia_id' => resistencia(280)->id,
        'volumen_m3' => 8.5,
        'foto_ruta' => "guias/{$proyecto->id}/a.jpg",
        'estado_asignacion' => EstadoAsignacion::SinSector,
        'origen' => OrigenGuia::Manual,
        'registrado_por' => User::factory()->rol(Rol::Almacenero)->create()->id,
    ]);

    return $proyecto;
}

describe('selección vacía en la exportación en ZIP (CU18)', function () {
    it('rechaza ids vacío con 422 y no lee el disco', function (string $consulta) {
        Storage::fake();
        $proveedor = Proveedor::factory()->create();
        $proyecto = proyectoConGuiaDe($proveedor);
        $usuario = asignadoA($proyecto);
        // ArmadorZip es lo único que lee el disco: la validación tiene que cortar antes.
        $this->mock(ArmadorZip::class)->shouldNotReceive('medir', 'transmitir');

        $this->actingAs($usuario)
            ->getJson("/api/v1/proyectos/{$proyecto->id}/documentos/guias/zip?".str_replace('{p}', (string) $proveedor->id, $consulta))
            ->assertStatus(422)
            ->assertJsonPath('errors.ids.0', 'Seleccione al menos un documento')
            ->assertHeaderMissing('Content-Disposition');
    })->with([
        'ids= solo' => 'ids=',
        'ids= con filtro de proveedor' => 'ids=&proveedor_id={p}',
    ]);

    it('sin ids, el filtro sigue exportando lo filtrado', function () {
        Storage::fake();
        $proveedor = Proveedor::factory()->create();
        $proyecto = proyectoConGuiaDe($proveedor);

        $respuesta = $this->actingAs(asignadoA($proyecto))
            ->get("/api/v1/proyectos/{$proyecto->id}/documentos/guias/zip?proveedor_id={$proveedor->id}")
            ->assertOk();

        expect($respuesta->streamedContent())->toContain('foto-a');
    });

    it('con ids, el cuerpo JSON vacío también se rechaza', function () {
        Storage::fake();
        $proyecto = proyectoConGuiaDe(Proveedor::factory()->create());
        $this->mock(ArmadorZip::class)->shouldNotReceive('medir', 'transmitir');

        $this->actingAs(asignadoA($proyecto))
            ->json('GET', "/api/v1/proyectos/{$proyecto->id}/documentos/guias/zip", ['ids' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.ids.0', 'Seleccione al menos un documento');
    });
});
