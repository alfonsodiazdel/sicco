<?php

use App\Enums\Rol;

it('define los cinco roles del documento SICCO', function () {
    expect(array_column(Rol::cases(), 'value'))->toBe([
        'administrador',
        'almacenero',
        'ingeniero_produccion',
        'oficina_tecnica',
        'gerencia',
    ]);
});

it('tiene nombre y descripción para cada rol', function (Rol $rol) {
    expect($rol->nombre())->not->toBeEmpty()
        ->and($rol->descripcion())->not->toBeEmpty();
})->with(Rol::cases());
