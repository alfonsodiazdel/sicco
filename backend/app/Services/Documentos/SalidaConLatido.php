<?php

namespace App\Services\Documentos;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Avisa que la respuesta sigue saliendo, también a mitad de un archivo grande: un cliente lento
 * puede tardar más que el plazo del candado en bajar un solo archivo.
 */
class SalidaConLatido implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $ultimo;

    /** @param  callable  $alAvanzar  Se llama tras una escritura, a lo sumo una vez cada $cadaSegundos. */
    public function __construct(private StreamInterface $stream, private $alAvanzar, private int $cadaSegundos)
    {
        $this->ultimo = now()->getTimestamp();
    }

    public function write($string): int
    {
        $escritos = $this->stream->write($string);
        // now() y no time(): así las pruebas pueden adelantar el reloj.
        if (now()->getTimestamp() - $this->ultimo >= $this->cadaSegundos) {
            $this->ultimo = now()->getTimestamp();
            ($this->alAvanzar)();
        }

        return $escritos;
    }
}
