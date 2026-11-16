<?php

namespace Database\Seeders;

use App\Models\ElementoEstructural;
use App\Models\GrupoPartida;
use Illuminate\Database\Seeder;

/**
 * Grupos iniciales del catálogo de partidas. Se ejecuta también en producción; no pisa los
 * cambios que Oficina técnica haga después en el catálogo.
 */
class GrupoPartidaSeeder extends Seeder
{
    /**
     * Grupos en su orden y los elementos estructurales que admite cada uno. Incluye combinaciones
     * poco comunes pero válidas (viga de cimentación, platea como losa maciza): Oficina técnica las
     * quita o agrega desde los catálogos. La migración de `grupo_elemento` usa la misma lista.
     */
    public const ELEMENTOS = [
        'Muros anclados' => ['Placa', 'Cimiento corrido', 'Viga'],
        'Cimentación' => ['Zapata', 'Cimiento corrido', 'Viga', 'Losa maciza'],
        'Cisterna' => ['Placa', 'Viga', 'Losa maciza'],
        'Verticales sótanos' => ['Columna', 'Placa', 'Escalera'],
        'Horizontales sótanos' => ['Viga', 'Losa aligerada', 'Losa maciza', 'Escalera'],
        'Verticales superestructura' => ['Columna', 'Placa', 'Escalera'],
        'Horizontales superestructura' => ['Viga', 'Losa aligerada', 'Losa maciza', 'Escalera'],
    ];

    public function run(): void
    {
        $indice = 0;
        foreach (self::ELEMENTOS as $nombre => $elementos) {
            $grupo = GrupoPartida::firstOrCreate(['nombre' => $nombre], [
                'partida_control' => '2.02.02',
                'orden' => ++$indice,
            ]);
            // Solo al crearlo: un grupo que ya existe conserva los elementos que le dejó Oficina técnica.
            if ($grupo->wasRecentlyCreated) {
                $grupo->elementos()->sync(ElementoEstructural::whereIn('nombre', $elementos)->pluck('id'));
            }
        }
    }
}
