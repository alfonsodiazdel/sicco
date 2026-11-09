<?php

use App\Enums\Rol;
use App\Models\ReporteGenerado;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('guarda y lee una generación del reporte EAT', function () {
    $this->travelTo(Carbon::parse('2026-11-09 12:00:00'));
    $proyecto = obra();
    $usuario = conRol(Rol::OficinaTecnica);

    $creado = ReporteGenerado::create([
        'usuario_id' => $usuario->id,
        'proyecto_id' => $proyecto->id,
        'tipo' => ReporteGenerado::TIPO_EAT,
        'parametros' => ['fecha_corte' => '2026-10-31'],
        'formato' => ReporteGenerado::FORMATO_PANTALLA,
    ]);

    $leido = ReporteGenerado::findOrFail($creado->id);

    expect($leido->usuario->is($usuario))->toBeTrue()
        ->and($leido->proyecto->is($proyecto))->toBeTrue()
        ->and($leido->tipo)->toBe('eat')
        ->and($leido->parametros)->toBe(['fecha_corte' => '2026-10-31'])
        ->and($leido->formato)->toBe('pantalla')
        ->and($leido->created_at->toDateTimeString())->toBe('2026-11-09 12:00:00');
});

it('rechaza un proyecto que no existe', function () {
    $usuario = conRol(Rol::OficinaTecnica);

    expect(fn () => ReporteGenerado::create([
        'usuario_id' => $usuario->id,
        'proyecto_id' => 999999,
        'tipo' => ReporteGenerado::TIPO_EAT,
        'parametros' => ['fecha_corte' => '2026-10-31'],
        'formato' => ReporteGenerado::FORMATO_PANTALLA,
    ]))->toThrow(QueryException::class);

    expect(ReporteGenerado::count())->toBe(0);
});
