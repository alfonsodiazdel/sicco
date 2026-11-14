<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Antes era una lista fija en config/sicco.php. `partidas.elemento` sigue siendo texto:
        // las partidas existentes no se reescriben.
        Schema::create('elementos_estructurales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 30)->unique();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $ahora = now();
        DB::table('elementos_estructurales')->insert(array_map(
            fn (string $nombre) => ['nombre' => $nombre, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['Zapata', 'Cimiento corrido', 'Columna', 'Placa', 'Viga', 'Losa aligerada', 'Losa maciza', 'Escalera'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('elementos_estructurales');
    }
};
