<?php

namespace Database\Seeders;

use App\Models\GrupoPartida;
use Illuminate\Database\Seeder;

/**
 * Grupos iniciales del catálogo de partidas. Se ejecuta también en producción; no pisa los
 * cambios que Oficina técnica haga después en el catálogo.
 */
class GrupoPartidaSeeder extends Seeder
{
    private const GRUPOS = [
        'Muros anclados',
        'Cimentación',
        'Cisterna',
        'Verticales sótanos',
        'Horizontales sótanos',
        'Verticales superestructura',
        'Horizontales superestructura',
    ];

    public function run(): void
    {
        foreach (self::GRUPOS as $indice => $nombre) {
            GrupoPartida::firstOrCreate(['nombre' => $nombre], [
                'partida_control' => '2.02.02',
                'orden' => $indice + 1,
            ]);
        }
    }
}
