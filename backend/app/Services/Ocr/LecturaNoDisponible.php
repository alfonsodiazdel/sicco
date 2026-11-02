<?php

namespace App\Services\Ocr;

use RuntimeException;

/** CU07 flujo alternativo 1: el servicio no respondió o no reconoció la guía; CU06 sigue con CU08. */
class LecturaNoDisponible extends RuntimeException {}
