<?php

namespace App\Enums;

/** Cómo se registró la guía: con los datos leídos por OCR (CU06/CU07) o digitados (CU08). */
enum OrigenGuia: string
{
    case Ocr = 'ocr';
    case Manual = 'manual';
}
