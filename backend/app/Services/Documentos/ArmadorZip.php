<?php

namespace App\Services\Documentos;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Arma en un archivo temporal el ZIP con los documentos y un manifest.txt con lo pedido, lo
 * incluido y lo que falta. Cada archivo se copia a disco antes de agregarlo: ZipArchive no lee
 * nada hasta close(), y con addFromString tendría todo el lote en memoria.
 */
class ArmadorZip
{
    /**
     * @param  iterable<array{ruc: string, numero: string, ruta: ?string}>  $documentos
     * @param  list<string>  $encabezado  Lo que se pidió, una línea por dato.
     * @return string Ruta del ZIP; quien lo envía lo borra.
     */
    public function armar(iterable $documentos, array $encabezado): string
    {
        $disco = Storage::disk();
        $ruta = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        if ($ruta === false || $zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el ZIP.');
        }

        $copias = [];
        $usados = ['manifest.txt' => true];
        $incluidos = [];
        $faltantes = [];
        try {
            foreach ($documentos as $documento) {
                $etiqueta = "{$documento['ruc']}-{$documento['numero']}";
                $flujo = $documento['ruta'] ? $disco->readStream($documento['ruta']) : null;
                if (! $flujo) {
                    $faltantes[] = $etiqueta.': '.($documento['ruta'] ? 'no está en el disco' : 'sin archivo');

                    continue;
                }

                $copia = tempnam(sys_get_temp_dir(), 'doc');
                $destino = fopen($copia, 'wb');
                stream_copy_to_stream($flujo, $destino);
                fclose($destino);
                fclose($flujo);
                $copias[] = $copia;

                $nombre = self::nombreUnico(
                    self::sanear($etiqueta),
                    self::sanear(strtolower(pathinfo($documento['ruta'], PATHINFO_EXTENSION))),
                    $usados,
                );
                $zip->addFile($copia, $nombre);
                $incluidos[] = $nombre;
            }

            $zip->addFromString('manifest.txt', implode("\n", [
                ...$encabezado,
                'Documentos encontrados: '.(count($incluidos) + count($faltantes)),
                '',
                'Incluidos ('.count($incluidos).'):',
                ...self::lista($incluidos),
                '',
                'Faltantes ('.count($faltantes).'):',
                ...self::lista($faltantes),
            ])."\n");
            if (! $zip->close()) {
                throw new RuntimeException('No se pudo cerrar el ZIP.');
            }
        } catch (\Throwable $e) {
            @unlink($ruta);

            throw $e;
        } finally {
            array_map('unlink', $copias);
        }

        return $ruta;
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
