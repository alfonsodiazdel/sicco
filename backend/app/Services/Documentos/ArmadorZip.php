<?php

namespace App\Services\Documentos;

use Aws\ResultInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Each;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Throwable;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Envía el ZIP mientras lo arma, sin escribir nada en disco: cada archivo pasa del disco de
 * documentos a la respuesta. Va sin compresión porque los JPG y PDF ya vienen comprimidos. El
 * manifest.txt va al final, para listar lo incluido y lo que faltó sin leer el lote dos veces.
 */
class ArmadorZip
{
    /**
     * Pesa cada archivo con los metadatos del disco, sin leerlo.
     *
     * @param  iterable<array{ruc: string, numero: string, ruta: ?string}>  $documentos
     * @return array{bytes: int, documentos: list<array{ruc: string, numero: string, ruta: ?string, bytes: ?int}>}
     */
    public function medir(iterable $documentos): array
    {
        $disco = Storage::disk();
        if ($disco instanceof AwsS3V3Adapter) {
            $medidos = $this->medirEnS3($disco, $documentos);
        } else {
            $medidos = [];
            foreach ($documentos as $documento) {
                try {
                    $bytes = $documento['ruta'] ? $disco->size($documento['ruta']) : null;
                } catch (FilesystemException) {
                    // Una ruta con «..» o sin archivo no se lee: se trata como archivo ausente.
                    $bytes = null;
                }
                $medidos[] = [...$documento, 'bytes' => $bytes];
            }
        }

        return ['bytes' => array_sum(array_column($medidos, 'bytes')), 'documentos' => $medidos];
    }

    /**
     * Lo mismo que el recorrido en serie, pero con hasta 10 HEAD a la vez: en S3 cada uno es un
     * viaje de ida y vuelta, y en serie un lote grande suma la latencia de todos.
     *
     * @param  iterable<array{ruc: string, numero: string, ruta: ?string}>  $documentos
     * @return list<array{ruc: string, numero: string, ruta: ?string, bytes: ?int}>
     */
    private function medirEnS3(AwsS3V3Adapter $disco, iterable $documentos): array
    {
        $config = $disco->getConfig();
        $medidos = [];
        $heads = function () use ($disco, $config, $documentos, &$medidos) {
            foreach ($documentos as $documento) {
                $i = count($medidos);
                $medidos[] = [...$documento, 'bytes' => null];
                try {
                    // path() normaliza y antepone root y prefix como lo hace size(); con «..» falla antes del HEAD.
                    $clave = $documento['ruta'] ? $disco->path($documento['ruta']) : null;
                } catch (FilesystemException) {
                    $clave = null;
                }
                if ($clave === null) {
                    continue;
                }
                try {
                    $head = $disco->getClient()->headObjectAsync(
                        ['Bucket' => $config['bucket'], 'Key' => $clave] + ($config['options'] ?? []),
                    );
                } catch (Throwable $e) {
                    // El SDK valida los parámetros al crear el comando y puede lanzar sin promesa.
                    $head = Create::rejectionFor($e);
                }
                yield $i => $head;
            }
        };

        // ofLimit y no ofLimitAll: un HEAD rechazado (404, 500, red) deja su archivo en null y el resto sigue.
        Each::ofLimit(
            $heads(),
            10,
            function (ResultInterface $resultado, int $i) use (&$medidos) {
                $medidos[$i]['bytes'] = isset($resultado['ContentLength']) ? (int) $resultado['ContentLength'] : null;
            },
            fn () => null,
        )->wait();

        return $medidos;
    }

    /**
     * @param  list<array{ruc: string, numero: string, ruta: ?string, bytes: ?int}>  $documentos  Lo que devuelve medir().
     * @param  list<string>  $encabezado  Lo que se pidió, una línea por dato.
     * @param  ?callable  $alAvanzar  Se llama mientras el ZIP sale, a lo sumo una vez por décima del plazo del candado.
     * @param  resource|null  $salida  Por defecto, la salida de la respuesta.
     */
    public function transmitir(array $documentos, array $encabezado, ?callable $alAvanzar = null, $salida = null): void
    {
        $disco = Storage::disk();
        $destino = new SalidaConLatido(
            Utils::streamFor($salida ?? fopen('php://output', 'wb')),
            $alAvanzar ?? fn () => null,
            intdiv(config('services.exportaciones.candado_segundos'), 10),
        );
        $zip = new ZipStream(
            outputStream: $destino,
            sendHttpHeaders: false,
            defaultCompressionMethod: CompressionMethod::STORE,
            defaultEnableZeroHeader: true,
            flushOutput: $salida === null,
        );

        $usados = ['manifest.txt' => true];
        $incluidos = [];
        $faltantes = [];
        foreach ($documentos as $documento) {
            $etiqueta = "{$documento['ruc']}-{$documento['numero']}";
            try {
                $flujo = $documento['bytes'] !== null ? $disco->readStream($documento['ruta']) : null;
            } catch (FilesystemException) {
                $flujo = null;
            }
            if (! $flujo) {
                $faltantes[] = $etiqueta.': '.($documento['ruta'] ? 'no está en el disco' : 'sin archivo');

                continue;
            }

            $nombre = self::nombreUnico(
                self::sanear($etiqueta),
                self::sanear(strtolower(pathinfo($documento['ruta'], PATHINFO_EXTENSION))),
                $usados,
            );
            try {
                $zip->addFileFromStream(fileName: $nombre, stream: $flujo);
                $leidos = ftell($flujo);
            } catch (Throwable) {
                // La respuesta ya empezó: no hay forma de devolver un error, se sigue con el resto.
                $leidos = false;
            } finally {
                fclose($flujo);
            }

            if ($leidos === false) {
                $faltantes[] = "{$etiqueta}: falló la lectura";
            } elseif ($leidos !== $documento['bytes']) {
                $faltantes[] = "{$etiqueta}: incompleto ({$leidos} de {$documento['bytes']} bytes) en {$nombre}";
            } else {
                $incluidos[] = $nombre;
            }
        }

        $zip->addFile(fileName: 'manifest.txt', data: implode("\n", [
            ...$encabezado,
            'Documentos encontrados: '.count($documentos),
            '',
            'Incluidos ('.count($incluidos).'):',
            ...self::lista($incluidos),
            '',
            'Faltantes ('.count($faltantes).'):',
            ...self::lista($faltantes),
        ])."\n");
        $zip->finish();
        // Si no, al destruirse el envoltorio PSR-7 cerraría también la $salida de quien llama.
        $destino->detach();
    }

    /** Deja solo letras sin tilde, dígitos, punto, guion y guion bajo: «/» o «\» nunca crean carpetas. */
    public static function sanear(string $texto): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $texto);
    }

    /** @param  array<string, true>  $usados */
    private static function nombreUnico(string $base, string $extension, array &$usados): string
    {
        $sufijo = $extension === '' ? '' : ".{$extension}";
        $nombre = $base.$sufijo;
        // Sin distinguir mayúsculas: Windows las trata como el mismo archivo al descomprimir.
        for ($i = 2; isset($usados[strtolower($nombre)]); $i++) {
            $nombre = "{$base}-{$i}{$sufijo}";
        }
        $usados[strtolower($nombre)] = true;

        return $nombre;
    }

    /** @param  list<string>  $lineas */
    private static function lista(array $lineas): array
    {
        return array_map(fn (string $l) => "- {$l}", $lineas ?: ['ninguno']);
    }
}
