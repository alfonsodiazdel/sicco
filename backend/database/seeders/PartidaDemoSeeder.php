<?php

namespace Database\Seeders;

use App\Models\GrupoPartida;
use App\Models\Proyecto;
use Illuminate\Database\Seeder;

/**
 * Las partidas de ejemplo de las pantallas. SOLO para desarrollo local; necesita los proyectos de
 * ProyectoDemoSeeder y los grupos de GrupoPartidaSeeder.
 */
class PartidaDemoSeeder extends Seeder
{
    // Proyecto, grupo, ítem, descripción, elemento, f'c, metrado, % desperdicio, P.U.
    private const PARTIDAS = [
        ['PRY-001', 'Cimentación', '03.01.02.01', "CONCRETO F'C=210 kg/cm2 - ZAPATAS", 'Zapata', "f'c 210 kg/cm²", 320, 5, 385],
        ['PRY-001', 'Verticales superestructura', '03.02.03.01', "CONCRETO PREMEZ. F'C=280 kg/cm2 - COLUMNAS", 'Columna', "f'c 280 kg/cm²", 180.5, 3, 420],
        ['PRY-001', 'Horizontales superestructura', null, "CONCRETO F'C=210 kg/cm2 - LOSA ALIGERADA H=20cm", 'Losa aligerada', "f'c 210 kg/cm²", 245, 5, 390],
        ['PRY-002', 'Verticales sótanos', '03.02.02.01', "CONCRETO PREMEZ. F'C=350 kg/cm2 - PLACAS", 'Placa', "f'c 350 kg/cm²", 3850, 3, 465],
    ];

    public function run(): void
    {
        foreach (self::PARTIDAS as [$codigo, $grupo, $item, $descripcion, $elemento, $fc, $metrado, $desperdicio, $precio]) {
            Proyecto::where('codigo', $codigo)->firstOrFail()->partidas()->firstOrCreate(
                ['descripcion' => $descripcion],
                [
                    'grupo_id' => GrupoPartida::where('nombre', $grupo)->firstOrFail()->id,
                    'item' => $item,
                    'elemento' => $elemento,
                    'resistencia_fc' => $fc,
                    'metrado_m3' => $metrado,
                    'factor_desperdicio' => $desperdicio,
                    'precio_unitario' => $precio,
                ],
            );
        }
    }
}
