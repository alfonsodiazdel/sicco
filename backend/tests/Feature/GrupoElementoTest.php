<?php

use App\Enums\Rol;
use App\Models\ElementoEstructural;
use App\Models\GrupoPartida;
use App\Models\Partida;
use Database\Seeders\GrupoPartidaSeeder;
use Illuminate\Support\Facades\DB;

/** El id del elemento del catálogo con ese nombre. */
function idElemento(string $nombre): int
{
    return ElementoEstructural::where('nombre', $nombre)->value('id');
}

/** Un grupo de prueba que admite esos elementos. */
function grupoCon(string $nombre, array $elementos, int $orden = 1): GrupoPartida
{
    $grupo = GrupoPartida::create(['nombre' => $nombre, 'partida_control' => '2.02.02', 'orden' => $orden]);
    $grupo->elementos()->sync(array_map(idElemento(...), $elementos));

    return $grupo;
}

function nombresDeElementos(GrupoPartida $grupo): array
{
    return $grupo->elementos()->pluck('nombre')->all();
}

describe('catálogo inicial', function () {
    it('el seeder crea cada grupo con sus elementos', function () {
        $this->seed(GrupoPartidaSeeder::class);

        $grupo = fn (string $nombre) => GrupoPartida::where('nombre', $nombre)->sole();
        expect(nombresDeElementos($grupo('Cimentación')))->toBe(['Zapata', 'Cimiento corrido', 'Viga', 'Losa maciza'])
            ->and(nombresDeElementos($grupo('Verticales sótanos')))->toBe(['Columna', 'Placa', 'Escalera'])
            ->and(nombresDeElementos($grupo('Cisterna')))->not->toContain('Columna')
            ->and(DB::table('grupo_elemento')->count())->toBe(24);
    });

    it('al volver a ejecutarse no repone los elementos que Oficina técnica quitó', function () {
        $this->seed(GrupoPartidaSeeder::class);
        $cimentacion = GrupoPartida::where('nombre', 'Cimentación')->sole();
        $cimentacion->elementos()->detach(idElemento('Viga'));

        $this->seed(GrupoPartidaSeeder::class);

        expect(nombresDeElementos($cimentacion))->not->toContain('Viga');
    });
});

describe('mantenimiento desde los grupos', function () {
    it('lista cada grupo con los ids de sus elementos', function () {
        $g = grupoCon('Cimentación', ['Zapata', 'Viga']);

        $this->actingAs(conRol(Rol::OficinaTecnica))->getJson('/api/v1/grupos-partida')
            ->assertOk()
            ->assertJsonPath('0.id', $g->id)
            ->assertJsonPath('0.elementos', [idElemento('Zapata'), idElemento('Viga')]);
    });

    it('crea un grupo con sus elementos', function () {
        $this->actingAs(conRol(Rol::OficinaTecnica))->postJson('/api/v1/grupos-partida', [
            'nombre' => 'Obras exteriores', 'partida_control' => '2.02.02', 'orden' => 8,
            'elementos' => [idElemento('Losa maciza'), idElemento('Cimiento corrido')],
        ])
            ->assertCreated()
            ->assertJsonPath('elementos', [idElemento('Cimiento corrido'), idElemento('Losa maciza')]);
    });

    it('al editar reemplaza los elementos; sin el campo los deja como están', function () {
        $g = grupoCon('Cimentación', ['Zapata', 'Viga']);
        $u = conRol(Rol::OficinaTecnica);
        $base = ['nombre' => 'Cimentación', 'partida_control' => '2.02.02', 'orden' => 1];

        $this->actingAs($u)->putJson("/api/v1/grupos-partida/{$g->id}", [...$base, 'elementos' => [idElemento('Zapata'), idElemento('Losa maciza')]])
            ->assertOk();
        expect(nombresDeElementos($g))->toBe(['Zapata', 'Losa maciza']);

        $this->actingAs($u)->putJson("/api/v1/grupos-partida/{$g->id}", [...$base, 'orden' => 2])->assertOk();
        expect(nombresDeElementos($g))->toBe(['Zapata', 'Losa maciza']);
    });

    it('rechaza ids que no existen o repetidos', function () {
        $this->actingAs(conRol(Rol::OficinaTecnica))->postJson('/api/v1/grupos-partida', [
            'nombre' => 'Obras exteriores', 'partida_control' => '2.02.02', 'orden' => 8,
            'elementos' => [999, idElemento('Viga'), idElemento('Viga')],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['elementos.0' => 'El elemento no existe', 'elementos.1' => 'El elemento está repetido']);
    });

    it('no quita un elemento que usan partidas del grupo, pero sí uno sin uso', function () {
        $g = grupoCon('Cimentación', ['Zapata', 'Viga']);
        partida(obra(), datosPartida(['grupo_id' => $g->id, 'elemento' => 'Zapata']));
        $u = conRol(Rol::OficinaTecnica);
        $base = ['nombre' => 'Cimentación', 'partida_control' => '2.02.02', 'orden' => 1];

        $this->actingAs($u)->putJson("/api/v1/grupos-partida/{$g->id}", [...$base, 'elementos' => [idElemento('Viga')]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['elementos' => 'No se puede quitar Zapata: hay partidas de este grupo que lo usan']);
        expect(nombresDeElementos($g))->toBe(['Zapata', 'Viga']);

        $this->actingAs($u)->putJson("/api/v1/grupos-partida/{$g->id}", [...$base, 'elementos' => [idElemento('Zapata')]])
            ->assertOk();
        expect(nombresDeElementos($g))->toBe(['Zapata']);
    });

    it('al eliminar el grupo se van también sus combinaciones', function () {
        $g = grupoCon('Obras exteriores', ['Zapata']);

        $this->actingAs(conRol(Rol::OficinaTecnica))->deleteJson("/api/v1/grupos-partida/{$g->id}")->assertNoContent();

        expect(DB::table('grupo_elemento')->where('grupo_id', $g->id)->exists())->toBeFalse();
    });
});

describe('mantenimiento desde los elementos', function () {
    it('crea un elemento ya asignado a grupos', function () {
        $sotanos = grupoCon('Verticales sótanos', [], 4);
        $muros = grupoCon('Muros anclados', [], 1);

        $r = $this->actingAs(conRol(Rol::OficinaTecnica))->postJson('/api/v1/elementos-estructurales', [
            'nombre' => 'Muro de contención', 'grupos' => [$sotanos->id, $muros->id],
        ])->assertCreated();

        // Por el orden de los grupos en el presupuesto.
        $r->assertJsonPath('grupos', [$muros->id, $sotanos->id]);
        expect(nombresDeElementos($sotanos))->toBe(['Muro de contención']);
    });

    it('al activar o desactivar, sin el campo, conserva sus grupos', function () {
        $g = grupoCon('Cimentación', ['Viga']);
        $viga = ElementoEstructural::find(idElemento('Viga'));

        $this->actingAs(conRol(Rol::OficinaTecnica))->putJson("/api/v1/elementos-estructurales/{$viga->id}", ['nombre' => 'Viga', 'activo' => false])
            ->assertOk()
            ->assertJsonPath('grupos', [$g->id]);
    });

    it('no quita un grupo cuyas partidas usan el elemento, pero sí uno sin uso', function () {
        $cimentacion = grupoCon('Cimentación', ['Viga'], 2);
        $horizontales = grupoCon('Horizontales sótanos', ['Viga'], 5);
        partida(obra(), datosPartida(['grupo_id' => $cimentacion->id, 'elemento' => 'Viga']));
        $u = conRol(Rol::OficinaTecnica);
        $viga = idElemento('Viga');

        $this->actingAs($u)->putJson("/api/v1/elementos-estructurales/{$viga}", ['nombre' => 'Viga', 'grupos' => [$horizontales->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grupos' => 'No se puede quitar Cimentación: hay partidas de ese grupo con este elemento']);

        $this->actingAs($u)->putJson("/api/v1/elementos-estructurales/{$viga}", ['nombre' => 'Viga', 'grupos' => [$cimentacion->id]])
            ->assertOk()
            ->assertJsonPath('grupos', [$cimentacion->id]);
    });

    it('el listado para los combos también trae los grupos', function () {
        $g = grupoCon('Cimentación', ['Zapata']);

        $zapata = collect($this->actingAs(conRol(Rol::Almacenero))->getJson('/api/v1/elementos-estructurales')->assertOk()->json())
            ->firstWhere('nombre', 'Zapata');

        expect($zapata['grupos'])->toBe([$g->id]);
    });
});

describe('la partida solo elige un elemento de su grupo', function () {
    it('acepta un elemento del grupo y rechaza uno que el grupo no admite', function () {
        $p = obra();
        $cisterna = grupoCon('Cisterna', ['Placa', 'Losa maciza']);
        $u = asignadoA($p);
        $datos = fn (string $elemento) => [...datosPartida(), 'grupo_id' => $cisterna->id, 'elemento' => $elemento];

        $this->actingAs($u)->postJson("/api/v1/proyectos/{$p->id}/partidas", $datos('Placa'))->assertCreated();
        $this->actingAs($u)->postJson("/api/v1/proyectos/{$p->id}/partidas", $datos('Columna'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['elemento' => 'El elemento no corresponde al grupo elegido']);
        expect(Partida::count())->toBe(1);
    });

    it('rechaza cambiar de grupo si el nuevo no admite el elemento', function () {
        $p = obra();
        $verticales = grupoCon('Verticales sótanos', ['Columna']);
        $horizontales = grupoCon('Horizontales sótanos', ['Viga'], 2);
        $partida = partida($p, datosPartida(['grupo_id' => $verticales->id, 'elemento' => 'Columna']));

        $this->actingAs(asignadoA($p))->putJson("/api/v1/partidas/{$partida->id}", [
            ...datosPartida(['grupo_id' => $verticales->id, 'elemento' => 'Columna']), 'grupo_id' => $horizontales->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['elemento' => 'El elemento no corresponde al grupo elegido']);
    });

    it('al editar acepta el par que ya tiene la partida aunque se haya quitado del catálogo', function () {
        $p = obra();
        $g = grupoCon('Cimentación', ['Zapata']);
        $datos = datosPartida(['grupo_id' => $g->id, 'elemento' => 'Zapata']);
        $partida = partida($p, $datos);
        DB::table('grupo_elemento')->where('grupo_id', $g->id)->delete();

        $this->actingAs(asignadoA($p))->putJson("/api/v1/partidas/{$partida->id}", [...$datos, 'descripcion' => 'Editada'])
            ->assertOk();
    });
});
