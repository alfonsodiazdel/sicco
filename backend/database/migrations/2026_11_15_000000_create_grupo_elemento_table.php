<?php

use Database\Seeders\GrupoPartidaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qué elementos estructurales admite cada grupo: la partida solo puede elegir uno de su grupo.
        Schema::create('grupo_elemento', function (Blueprint $table) {
            $table->foreignId('grupo_id')->constrained('grupos_partida')->cascadeOnDelete();
            $table->foreignId('elemento_id')->constrained('elementos_estructurales')->cascadeOnDelete();
            $table->primary(['grupo_id', 'elemento_id']);
        });

        // En una base nueva los grupos aún no existen: los crea GrupoPartidaSeeder con sus elementos.
        // En una con datos se asignan las combinaciones iniciales a los grupos que tengan ese nombre…
        $grupos = DB::table('grupos_partida')->pluck('id', 'nombre');
        $elementos = DB::table('elementos_estructurales')->pluck('id', 'nombre');
        $filas = [];
        foreach (GrupoPartidaSeeder::ELEMENTOS as $grupo => $nombres) {
            foreach ($nombres as $nombre) {
                if (isset($grupos[$grupo], $elementos[$nombre])) {
                    $filas[] = ['grupo_id' => $grupos[$grupo], 'elemento_id' => $elementos[$nombre]];
                }
            }
        }
        // …y las que ya usan las partidas, para que ninguna quede fuera de lo permitido.
        $enUso = DB::table('partidas as p')
            ->join('elementos_estructurales as e', 'e.nombre', '=', 'p.elemento')
            ->distinct()
            ->get(['p.grupo_id', 'e.id as elemento_id']);
        foreach ($enUso as $par) {
            $filas[] = ['grupo_id' => $par->grupo_id, 'elemento_id' => $par->elemento_id];
        }

        DB::table('grupo_elemento')->insertOrIgnore($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('grupo_elemento');
    }
};
