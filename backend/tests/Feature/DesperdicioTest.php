<?php

use App\Enums\Rol;
use App\Services\Desperdicio\CalculadoraDesperdicio;

/** Proyecto con columnas (180.50 m³, 5 %) repartidas en dos niveles y un ingeniero asignado. */
function obraConVaciados(): array
{
    [$p, $partida, $ing] = obraConColumnas();
    $partida->update(['factor_desperdicio' => 5]);
    $a = sector(nivel($p, 'Piso 1', 1), $partida, 100, 'Sector A');
    $b = sector(nivel($p, 'Piso 2', 2), $partida, 80, 'Sector B');
    $a->forceFill(['volumen_vaciado_m3' => 106])->save(); // supera 100 × 1.05 = 105
    $b->forceFill(['volumen_vaciado_m3' => 60])->save();

    return [$p, $ing];
}

it('calcula por sector con diferencia, porcentaje y límite', function () {
    [$p, $ing] = obraConVaciados();

    $r = $this->actingAs($ing)->getJson("/api/v1/proyectos/{$p->id}/desperdicio")
        ->assertOk()
        ->assertJsonPath('detalle', 'sector')
        ->assertJsonPath('hay_vaciados', true)
        ->assertJsonCount(2, 'filas');

    expect($r->json('filas.0'))->toMatchArray([
        'etiqueta' => 'Sector A', 'nivel' => 'Piso 1',
        'presupuestado_m3' => 100, 'vaciado_m3' => 106, 'diferencia_m3' => 6,
        'desperdicio_pct' => 6, 'limite_m3' => 105, 'supera_limite' => true,
    ]);
    // Vació 20 m³ menos: la diferencia queda negativa, pero el desperdicio no baja de 0 %.
    expect($r->json('filas.1'))->toMatchArray(['diferencia_m3' => -20, 'desperdicio_pct' => 0, 'supera_limite' => false]);
});

it('agrupa por nivel y por elemento', function () {
    [$p, $ing] = obraConVaciados();

    $this->actingAs($ing)->getJson("/api/v1/proyectos/{$p->id}/desperdicio?detalle=nivel")
        ->assertOk()->assertJsonCount(2, 'filas')->assertJsonPath('filas.1.etiqueta', 'Piso 2');

    $r = $this->actingAs($ing)->getJson("/api/v1/proyectos/{$p->id}/desperdicio?detalle=elemento")
        ->assertOk()->assertJsonCount(1, 'filas');
    expect($r->json('filas.0'))->toMatchArray([
        'presupuestado_m3' => 180, 'vaciado_m3' => 166, 'limite_m3' => 189, 'supera_limite' => false,
    ]);
});

it('sin vaciados no hay porcentaje y lo indica', function () {
    [$p, $partida, $ing] = obraConColumnas();
    sector(nivel($p), $partida, 50);

    $this->actingAs($ing)->getJson("/api/v1/proyectos/{$p->id}/desperdicio")
        ->assertOk()
        ->assertJsonPath('hay_vaciados', false)
        ->assertJsonPath('filas.0.desperdicio_pct', null);
});

it('usa el mismo límite que la asignación (CU09), con el medio centésimo hacia arriba', function () {
    [$p, $partida, $ing] = obraConColumnas();
    $partida->update(['factor_desperdicio' => 5]);
    // 1.90 × 1.05 = 1.995 → límite 2.00 m³; en coma flotante salía 1.99.
    $justo = sector(nivel($p), $partida, 1.9, 'Justo');
    $pasado = sector(nivel($p, 'Piso 2', 2), $partida, 1.9, 'Pasado');
    $justo->forceFill(['volumen_vaciado_m3' => 2])->save();
    $pasado->forceFill(['volumen_vaciado_m3' => 2.01])->save();

    $r = $this->actingAs($ing)->getJson("/api/v1/proyectos/{$p->id}/desperdicio")->assertOk();

    expect($r->json('filas.0'))->toMatchArray(['limite_m3' => 2, 'supera_limite' => false]);
    expect($r->json('filas.1.supera_limite'))->toBeTrue();
    expect(CalculadoraDesperdicio::excede($justo->fresh()))->toBeFalse();
    expect(CalculadoraDesperdicio::excede($pasado->fresh()))->toBeTrue();
});

it('distingue con un id las filas de igual nombre', function () {
    [$p, $partida, $ing] = obraConColumnas();
    $otra = $p->partidas()->create(datosPartida([
        'item' => '03.02.01', 'elemento' => 'Columna', 'resistencia_id' => $partida->resistencia_id, 'metrado_m3' => 50,
    ]));
    sector(nivel($p, 'Piso 1', 1), $partida, 10, 'Sector A');
    sector(nivel($p, 'Piso 2', 2), $otra, 10, 'Sector A');

    $sectores = $this->actingAs($ing)->getJson("/api/v1/proyectos/{$p->id}/desperdicio")->json('filas');
    expect(array_column($sectores, 'etiqueta'))->toBe(['Sector A', 'Sector A']);
    expect(array_unique(array_column($sectores, 'id')))->toHaveCount(2);

    $elementos = $this->actingAs($ing)->getJson("/api/v1/proyectos/{$p->id}/desperdicio?detalle=elemento")->json('filas');
    expect(array_unique(array_column($elementos, 'etiqueta')))->toHaveCount(1);
    expect(array_column($elementos, 'item'))->toBe([$partida->item, '03.02.01']);
});

it('rechaza un detalle inválido', function () {
    [$p, $ing] = obraConVaciados();

    $this->actingAs($ing)->getJson("/api/v1/proyectos/{$p->id}/desperdicio?detalle=x")
        ->assertUnprocessable()->assertJsonValidationErrors(['detalle']);
});

it('permite a gerencia y administrador, y niega a otros roles y proyectos ajenos', function () {
    [$p] = obraConVaciados();
    $url = "/api/v1/proyectos/{$p->id}/desperdicio";

    $this->actingAs(asignadoA($p, Rol::Gerencia))->getJson($url)->assertOk();
    $this->actingAs(conRol(Rol::Administrador))->getJson($url)->assertOk();
    $this->actingAs(asignadoA($p, Rol::OficinaTecnica))->getJson($url)->assertForbidden();
    $this->actingAs(asignadoA($p, Rol::Almacenero))->getJson($url)->assertForbidden();
    $this->actingAs(conRol(Rol::Gerencia))->getJson($url)->assertForbidden()->assertJsonPath('code', 'sin_permiso');
});
