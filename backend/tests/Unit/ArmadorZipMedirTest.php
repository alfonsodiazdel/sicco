<?php

use App\Services\Documentos\ArmadorZip;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Disco S3 real de Laravel cuyo cliente no sale a la red: cada HEAD queda pendiente hasta que
 * alguien lo espera, así se cuenta cuántos hay en vuelo a la vez.
 *
 * @param  array<string, int|string>  $objetos  Clave S3 => bytes, o 'falta' (404) o 'error' (500).
 * @return object{claves: list<string>, enVuelo: int, maximo: int}
 */
function discoS3Simulado(array $objetos): object
{
    $registro = (object) ['claves' => [], 'enVuelo' => 0, 'maximo' => 0];
    $manejador = function (CommandInterface $comando) use ($objetos, $registro) {
        $clave = $comando['Key'];
        $registro->claves[] = $clave;
        $registro->maximo = max($registro->maximo, ++$registro->enVuelo);
        $promesa = new Promise(function () use (&$promesa, $registro, $comando, $clave, $objetos) {
            /** @var Promise $promesa */
            $registro->enVuelo--;
            match ($objetos[$clave] ?? 'falta') {
                'falta' => $promesa->reject(new S3Exception('Not Found', $comando, ['code' => 'NotFound', 'response' => new Response(404)])),
                'error' => $promesa->reject(new S3Exception('Internal Error', $comando, ['response' => new Response(500)])),
                default => $promesa->resolve(new Result(['ContentLength' => $objetos[$clave]])),
            };
        });

        return $promesa;
    };

    Storage::set('s3-prueba', Storage::build([
        'driver' => 's3',
        'key' => 'k',
        'secret' => 's',
        'region' => 'us-east-1',
        'bucket' => 'documentos',
        'root' => 'raiz/sicco',
        'retries' => 0,
        'handler' => $manejador,
    ]));
    config(['filesystems.default' => 's3-prueba']);

    return $registro;
}

function documento(string $numero, ?string $ruta): array
{
    return ['ruc' => '20100030595', 'numero' => $numero, 'ruta' => $ruta];
}

it('mide en S3 igual que en serie: un faltante y un error solo anulan su archivo', function () {
    $registro = discoS3Simulado([
        'raiz/sicco/a.pdf' => 100,
        'raiz/sicco/c.pdf' => 'error',
        'raiz/sicco/d.jpg' => 25,
    ]);

    $documentos = [
        documento('1', 'a.pdf'),
        documento('2', 'b.pdf'),
        documento('3', 'c.pdf'),
        documento('4', null),
        documento('5', '/d.jpg'),
    ];

    expect((new ArmadorZip)->medir(collect($documentos)->lazy()))->toBe([
        'bytes' => 125,
        'documentos' => [
            [...$documentos[0], 'bytes' => 100],
            [...$documentos[1], 'bytes' => null],
            [...$documentos[2], 'bytes' => null],
            [...$documentos[3], 'bytes' => null],
            [...$documentos[4], 'bytes' => 25],
        ],
    ])->and($registro->claves)->toEqualCanonicalizing(['raiz/sicco/a.pdf', 'raiz/sicco/b.pdf', 'raiz/sicco/c.pdf', 'raiz/sicco/d.jpg']);
});

it('rechaza una ruta con «..» sin hacer el HEAD', function () {
    $registro = discoS3Simulado(['raiz/otro/x.pdf' => 9, 'raiz/sicco/a.pdf' => 7]);

    $medicion = (new ArmadorZip)->medir([documento('1', '../otro/x.pdf'), documento('2', 'a.pdf')]);

    expect(array_column($medicion['documentos'], 'bytes'))->toBe([null, 7])
        ->and($medicion['bytes'])->toBe(7)
        ->and($registro->claves)->toBe(['raiz/sicco/a.pdf']);
});

it('mide en serie, como antes, si el disco no es S3', function () {
    Storage::fake();
    Storage::put('a.pdf', str_repeat('x', 12));

    $medicion = (new ArmadorZip)->medir([
        documento('1', 'a.pdf'),
        documento('2', 'b.pdf'),
        documento('3', null),
        documento('4', '../a.pdf'),
    ]);

    expect(array_column($medicion['documentos'], 'bytes'))->toBe([12, null, null, null])
        ->and($medicion['bytes'])->toBe(12);
});

it('lanza los HEAD en paralelo, de a 10 como máximo', function () {
    $objetos = [];
    $documentos = [];
    foreach (range(1, 25) as $n) {
        $objetos["raiz/sicco/{$n}.pdf"] = $n;
        $documentos[] = documento((string) $n, "{$n}.pdf");
    }
    $registro = discoS3Simulado($objetos);

    $medicion = (new ArmadorZip)->medir($documentos);

    expect(array_column($medicion['documentos'], 'bytes'))->toBe(range(1, 25))
        ->and($medicion['bytes'])->toBe(325)
        ->and($registro->maximo)->toBe(10);
});
