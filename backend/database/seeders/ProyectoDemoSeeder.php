<?php

namespace Database\Seeders;

use App\Enums\EstadoProyecto;
use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Los proyectos de ejemplo de las pantallas y sus asignaciones. SOLO para desarrollo local;
 * necesita los usuarios de UsuarioDemoSeeder.
 */
class ProyectoDemoSeeder extends Seeder
{
    private const PROYECTOS = [
        ['PRY-001', 'Residencial Los Álamos', 'Av. Primavera 1250, Santiago de Surco', '2026-03-02', '2027-02-26'],
        ['PRY-002', 'Torre Empresarial Javier Prado', 'Av. Javier Prado Este 3580, San Isidro', '2026-01-12', '2027-12-17'],
        ['PRY-003', 'Condominio Las Palmeras', 'Av. La Molina 1150, La Molina', '2026-11-02', '2027-10-29'],
    ];

    private const ASIGNADOS = [
        'administrador@sicco.test' => ['PRY-001', 'PRY-002', 'PRY-003'],
        'almacenero@sicco.test' => ['PRY-001'],
        'ingeniero_produccion@sicco.test' => ['PRY-001', 'PRY-002'],
        'oficina_tecnica@sicco.test' => ['PRY-002'],
    ];

    public function run(): void
    {
        $ingeniero = User::where('email', 'ingeniero_produccion@sicco.test')->firstOrFail();

        foreach (self::PROYECTOS as [$codigo, $nombre, $direccion, $inicio, $fin]) {
            Proyecto::firstOrCreate(['codigo' => $codigo], [
                'nombre' => $nombre,
                'direccion' => $direccion,
                'fecha_inicio' => $inicio,
                'fecha_fin' => $fin,
                'ingeniero_id' => $ingeniero->id,
                'estado' => EstadoProyecto::Activo,
            ]);
        }

        foreach (self::ASIGNADOS as $correo => $codigos) {
            $usuario = User::where('email', $correo)->first();
            foreach (Proyecto::whereIn('codigo', $codigos)->get() as $proyecto) {
                $proyecto->usuarios()->syncWithoutDetaching([$usuario->id]);
            }
        }
    }
}
