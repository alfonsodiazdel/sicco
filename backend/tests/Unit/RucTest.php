<?php

use App\Support\Ruc;

it('valida el dígito verificador del RUC', function (string $ruc, bool $valido) {
    expect(Ruc::esValido($ruc))->toBe($valido);
})->with([
    ['20100030595', true],
    ['20100030596', false],
    ['10456789012', false],
    ['30100030595', false],
    ['2010003059', false],
]);
