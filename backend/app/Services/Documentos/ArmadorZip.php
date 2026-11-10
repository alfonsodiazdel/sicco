<?php

namespace App\Services\Documentos;

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
        $total = 0;
        $medidos = [];
        foreach ($documentos as $documento) {
            try {
                $bytes = $documento['ruta'] ? $disco->size($documento['ruta']) : null;
            } catch (FilesystemException) {
                // Una ruta con «..» o sin archivo no se lee: se trata como archivo ausente.
                $bytes = null;
            }
            $total += $bytes ?? 0;
            $medidos[] = [...$documento, 'bytes' => $bytes];
        }

        return ['bytes' => $total, 'documentos' => $medidos];
    }

    /**
     * @param  list<array{ruc: string, numero: string, ruta: ?string, bytes: ?int}>  $documentos  Lo que devuelve medir().
     * @param  list<string>  $encabezado  Lo que se pidió, una línea por dato.
     * @param  ?callable  $alAvanzar  Se llama tras cada archivo enviado.
     * @param  resource|null  $salida  Por defecto, la salida de la respuesta.
     */
    public function transmitir(array $documentos, array $encabezado, ?callable $alAvanzar = null, $salida = null): void
    {
        $disco = Storage::disk();
        $zip = new ZipStream(
            outputStream: $salida,
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
            if ($alAvanzar) {
                $alAvanzar();
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
