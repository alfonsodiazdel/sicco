<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El precio unitario deja la partida y pasa al concreto del proyecto: todas las partidas con el
     * mismo concreto comparten un precio. Hoy un concreto es solo su resistencia; más adelante podrá
     * llevar aditivos y entonces la clave única los incluirá.
     */
    public function up(): void
    {
        Schema::create('concretos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->foreignId('resistencia_id')->constrained('resistencias')->restrictOnDelete();
            // Soles por m³, sin IGV, como lo guardaba la partida.
            $table->decimal('precio_unitario', 12, 2);
            $table->timestamps();

            $table->unique(['proyecto_id', 'resistencia_id']);
        });

        Schema::table('partidas', function (Blueprint $table) {
            $table->foreignId('concreto_id')->nullable()->after('elemento')->constrained('concretos')->restrictOnDelete();
        });

        // Si las partidas de una resistencia tenían precios distintos, queda el de la última editada.
        $pares = DB::table('partidas')->select('proyecto_id', 'resistencia_id')->distinct()->get();
        foreach ($pares as $par) {
            $partidas = DB::table('partidas')
                ->where('proyecto_id', $par->proyecto_id)
                ->where('resistencia_id', $par->resistencia_id);
            $reciente = (clone $partidas)->orderByDesc('updated_at')->orderByDesc('id')->first();

            $concretoId = DB::table('concretos')->insertGetId([
                'proyecto_id' => $par->proyecto_id,
                'resistencia_id' => $par->resistencia_id,
                'precio_unitario' => $reciente->precio_unitario,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $partidas->update(['concreto_id' => $concretoId]);
        }

        Schema::table('partidas', function (Blueprint $table) {
            $table->foreignId('concreto_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('resistencia_id');
            $table->dropColumn('precio_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('partidas', function (Blueprint $table) {
            $table->foreignId('resistencia_id')->nullable()->after('elemento')->constrained('resistencias')->restrictOnDelete();
            $table->decimal('precio_unitario', 12, 2)->nullable()->after('factor_desperdicio');
        });

        DB::table('partidas')
            ->join('concretos', 'concretos.id', '=', 'partidas.concreto_id')
            ->update([
                'partidas.resistencia_id' => DB::raw('concretos.resistencia_id'),
                'partidas.precio_unitario' => DB::raw('concretos.precio_unitario'),
            ]);

        Schema::table('partidas', function (Blueprint $table) {
            $table->foreignId('resistencia_id')->nullable(false)->change();
            $table->decimal('precio_unitario', 12, 2)->nullable(false)->change();
            $table->dropConstrainedForeignId('concreto_id');
        });

        Schema::dropIfExists('concretos');
    }
};
