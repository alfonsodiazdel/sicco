<?php

namespace Database\Seeders;

use App\Models\Partida;
use App\Models\Proyecto;
use Illuminate\Database\Seeder;

/**
 * Los niveles y sectores de ejemplo de las pantallas. SOLO para desarrollo local; necesita las
 * partidas de PartidaDemoSeeder.
 */
class NivelDemoSeeder extends Seeder
{
    // Proyecto => [nombre del nivel, orden, [[sector, descripción de la partida, m³ presupuestados]]]
    private const NIVELES = [
        'PRY-001' => [
            ['Sótano 1', 1, [
                ['Zapatas eje 1-3', "CONCRETO F'C=210 kg/cm2 - ZAPATAS", 40],
                ['Zapatas eje 4-6', "CONCRETO F'C=210 kg/cm2 - ZAPATAS", 36],
            ]],
            ['Piso 1', 2, [
                ['Sector A', "CONCRETO PREMEZ. F'C=280 kg/cm2 - COLUMNAS", 60],
                ['Sector B', "CONCRETO PREMEZ. F'C=280 kg/cm2 - COLUMNAS", 58.5],
                ['Sector C', "CONCRETO F'C=210 kg/cm2 - LOSA ALIGERADA H=20cm", 122.5],
            ]],
            ['Piso 2', 3, []],
        ],
        'PRY-002' => [
            ['Piso 1', 1, []],
        ],
    ];

    public function run(): void
    {
        foreach (self::NIVELES as $codigo => $niveles) {
            $proyecto = Proyecto::where('codigo', $codigo)->firstOrFail();

            foreach ($niveles as [$nombre, $orden, $sectores]) {
                $nivel = $proyecto->niveles()->firstOrCreate(['nombre' => $nombre], ['orden' => $orden]);

                foreach ($sectores as [$sector, $descripcion, $volumen]) {
                    $partida = Partida::where('proyecto_id', $proyecto->id)
                        ->where('descripcion', $descripcion)
                        ->firstOrFail();
                    $nivel->sectores()->firstOrCreate(['nombre' => $sector], [
                        'presupuesto_id' => $partida->id,
                        'volumen_presup_m3' => $volumen,
                    ]);
                }
            }
        }
    }
}
