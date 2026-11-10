<?php

use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use App\Enums\Rol;
use App\Models\GuiaRemision;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

/** Un proyecto con una guía y su foto en el disco, y la URL de su ZIP. */
function proyectoConUnaGuiaExportable(): array
{
    $proyecto = obra();
    Storage::put("guias/{$proyecto->id}/a.jpg", 'foto-a');
    GuiaRemision::create([
        'proyecto_id' => $proyecto->id,
        'proveedor_id' => Proveedor::factory()->create()->id,
        'numero' => 'T001-00000101',
        'fecha' => '2026-11-05',
        'resistencia_id' => resistencia(280)->id,
        'volumen_m3' => 8.5,
        'foto_ruta' => "guias/{$proyecto->id}/a.jpg",
        'estado_asignacion' => EstadoAsignacion::SinSector,
        'origen' => OrigenGuia::Manual,
        'registrado_por' => User::factory()->rol(Rol::Almacenero)->create()->id,
    ]);

    return [$proyecto, "/api/v1/proyectos/{$proyecto->id}/documentos/guias/zip"];
}

describe('límite de solicitudes de toda la app', function () {
    it('la exportación en ZIP responde 429 en español, con Retry-After y X-RateLimit-*, al pasar las 5 por minuto', function () {
        [$proyecto, $url] = proyectoConUnaGuiaExportable();
        $usuario = asignadoA($proyecto);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($usuario)->getJson($url)->assertOk()->streamedContent();
        }

        $this->actingAs($usuario)->getJson($url)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Demasiadas solicitudes. Espere un minuto e inténtelo de nuevo.')
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');
    });

    it('el reporte EAT, con su propio límite, responde el mismo 429 en español', function () {
        config(['services.reportes.por_minuto' => 2]);
        $proyecto = obra();
        $usuario = asignadoA($proyecto);
        // Sin fecha de corte responde 422, pero la petición igual cuenta para el límite.
        $url = "/api/v1/proyectos/{$proyecto->id}/reportes/eat";
        $this->actingAs($usuario)->getJson($url)->assertStatus(422);
        $this->actingAs($usuario)->getJson($url)->assertStatus(422);

        $this->actingAs($usuario)->getJson($url)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Demasiadas solicitudes. Espere un minuto e inténtelo de nuevo.')
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '0');
    });

    it('el 429 sin cupo de exportaciones simultáneas conserva su propio mensaje', function () {
        [$proyecto, $url] = proyectoConUnaGuiaExportable();
        ocuparCuposDesdeOtroProceso();

        try {
            $this->actingAs(asignadoA($proyecto))->getJson($url)
                ->assertStatus(429)
                ->assertJsonPath('code', 'sin_cupo')
                ->assertJsonPath('message', 'Hay otras exportaciones en curso. Intente de nuevo en un minuto.');
        } finally {
            DB::disconnect('otro_proceso');
        }
    });
});
