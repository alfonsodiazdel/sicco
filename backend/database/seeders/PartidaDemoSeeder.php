<?php

namespace Database\Seeders;

use App\Models\Concreto;
use App\Models\GrupoPartida;
use App\Models\Proyecto;
use App\Models\Resistencia;
use Illuminate\Database\Seeder;

/**
 * Las partidas de ejemplo de las pantallas. SOLO para desarrollo local; necesita los proyectos de
 * ProyectoDemoSeeder, los grupos de GrupoPartidaSeeder y las resistencias de ResistenciaSeeder.
 */
class PartidaDemoSeeder extends Seeder
{
    // Proyecto, grupo, ítem, descripción, elemento, f'c, metrado, % desperdicio, P.U. del concreto
    // (las partidas con el mismo f'c en un proyecto comparten el precio).
    private const PARTIDAS = [
        ['PRY-001', 'Cimentación', '03.01.02.01', "CONCRETO F'C=210 kg/cm2 - ZAPATAS", 'Zapata', 210, 320, 5, 390],
        ['PRY-001', 'Verticales superestructura', '03.02.03.01', "CONCRETO PREMEZ. F'C=280 kg/cm2 - COLUMNAS", 'Columna', 280, 180.5, 3, 420],
        ['PRY-001', 'Horizontales superestructura', null, "CONCRETO F'C=210 kg/cm2 - LOSA ALIGERADA H=20cm", 'Losa aligerada', 210, 245, 5, 390],
        ['PRY-002', 'Verticales sótanos', '03.02.02.01', "CONCRETO PREMEZ. F'C=350 kg/cm2 - PLACAS", 'Placa', 350, 3850, 3, 465],
    ];

    public function run(): void
    {
        foreach (self::PARTIDAS as [$codigo, $grupo, $item, $descripcion, $elemento, $fc, $metrado, $desperdicio, $precio]) {
            $proyecto = Proyecto::where('codigo', $codigo)->firstOrFail();
            $concreto = Concreto::fijar($proyecto->id, Resistencia::where('fc_kg_cm2', $fc)->firstOrFail()->id, $precio);
            $proyecto->partidas()->firstOrCreate(
                ['descripcion' => $descripcion],
                [
                    'grupo_id' => GrupoPartida::where('nombre', $grupo)->firstOrFail()->id,
                    'item' => $item,
                    'elemento' => $elemento,
                    'concreto_id' => $concreto->id,
                    'metrado_m3' => $metrado,
                    'factor_desperdicio' => $desperdicio,
                ],
            );
        }
    }
}
