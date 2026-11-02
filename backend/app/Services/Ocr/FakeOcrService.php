<?php

namespace App\Services\Ocr;

use App\Contracts\OcrServiceInterface;

/**
 * OCR falso para desarrollo local y pruebas: no llama a la API de IA ni gasta en ella. Devuelve
 * siempre una guía de Holcim (ConcreteraSeeder), con la placa en baja confianza para ver cómo se
 * marca en la pantalla.
 */
class FakeOcrService implements OcrServiceInterface
{
    public function leerGuia(string $imagen, string $mediaType, array $indicaciones = []): LecturaGuia
    {
        $campo = fn (string|int|float $valor, float $confianza = 0.97) => ['valor' => $valor, 'confianza' => $confianza];

        return LecturaGuia::desdeDatos([
            'es_guia_remision' => true,
            'numero' => $campo('T001-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT)),
            'fecha' => $campo(today()->toDateString()),
            'proveedor' => $campo('HOLCIM PERU S.A.'),
            'ruc' => $campo('20380289360'),
            'codigo_producto' => $campo('70280NB5746'),
            'descripcion_producto' => $campo('HOLCIM CLASICO'),
            'resistencia' => $campo(280),
            'volumen_m3' => $campo(10),
            'placa' => $campo('ABC-123', 0.55),
        ], ['modelo' => 'fake']);
    }
}
