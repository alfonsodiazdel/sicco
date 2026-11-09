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

    /**
     * Una factura de Holcim como la F012-180490: 13 m³ de f'c 280 que cobran dos guías, más un
     * cargo de bombeo para ver cómo se separa del concreto.
     */
    public function leerFactura(string $archivo, string $mediaType, array $indicaciones = []): LecturaFactura
    {
        $campo = fn (string|int|float $valor, float $confianza = 0.97) => ['valor' => $valor, 'confianza' => $confianza];

        return LecturaFactura::desdeDatos([
            'es_factura' => true,
            'numero' => $campo('F012-'.random_int(100000, 999999)),
            'fecha' => $campo(today()->toDateString()),
            'proveedor' => $campo('HOLCIM PERU S.A.'),
            'ruc' => $campo('20380289360'),
            'subtotal' => $campo(4609),
            'igv' => $campo(829.62),
            'total' => $campo(5438.62),
            'items' => [
                ['descripcion' => 'HOLCIM CLASICO', 'codigo_producto' => '70280NB5746', 'resistencia' => 280, 'es_concreto' => true,
                    'cantidad' => 13, 'valor_unitario' => 293, 'importe' => 3809, 'confianza' => 0.95],
                ['descripcion' => 'SERVICIO DE BOMBEO', 'codigo_producto' => null, 'resistencia' => null, 'es_concreto' => false,
                    'cantidad' => 20, 'valor_unitario' => 40, 'importe' => 800, 'confianza' => 0.6],
            ],
            'guias' => ['T006-00012289', 'T006-00012292'],
        ], ['modelo' => 'fake']);
    }
}
