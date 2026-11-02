<?php

namespace Database\Seeders;

use App\Models\Resistencia;
use Illuminate\Database\Seeder;

/** Resistencias f'c iniciales. Se ejecuta también en producción; no pisa los cambios posteriores. */
class ResistenciaSeeder extends Seeder
{
    private const FC = [175, 210, 280, 350];

    public function run(): void
    {
        foreach (self::FC as $fc) {
            Resistencia::firstOrCreate(['fc_kg_cm2' => $fc], ['nombre' => "f'c {$fc} kg/cm²"]);
        }
    }
}
