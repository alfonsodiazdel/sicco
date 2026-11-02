<?php

namespace App\Contracts;

use App\Services\Ocr\LecturaGuia;
use App\Services\Ocr\LecturaNoDisponible;

/** CU07 Leer guía con OCR: servicio externo de OCR potenciado con IA. */
interface OcrServiceInterface
{
    /**
     * @param  string  $imagen  Contenido binario de la foto.
     * @param  list<string>  $indicaciones  Cómo indica cada concretera los datos en sus guías.
     *
     * @throws LecturaNoDisponible si el servicio no responde a tiempo, falla o la imagen no es una guía.
     */
    public function leerGuia(string $imagen, string $mediaType, array $indicaciones = []): LecturaGuia;
}
