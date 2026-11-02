<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // La resistencia de la partida pasa de texto («f'c 280 kg/cm²») al catálogo de resistencias.
    public function up(): void
    {
        Schema::table('partidas', function (Blueprint $table) {
            $table->foreignId('resistencia_id')->nullable()->after('elemento')->constrained('resistencias')->restrictOnDelete();
        });

        foreach (DB::table('partidas')->distinct()->pluck('resistencia_fc') as $texto) {
            $fc = (int) preg_replace('/\D/', '', $texto);
            DB::table('resistencias')->insertOrIgnore([
                'fc_kg_cm2' => $fc,
                'nombre' => "f'c {$fc} kg/cm²",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('partidas')->where('resistencia_fc', $texto)->update([
                'resistencia_id' => DB::table('resistencias')->where('fc_kg_cm2', $fc)->value('id'),
            ]);
        }

        Schema::table('partidas', function (Blueprint $table) {
            $table->foreignId('resistencia_id')->nullable(false)->change();
            $table->dropColumn('resistencia_fc');
        });
    }

    public function down(): void
    {
        Schema::table('partidas', function (Blueprint $table) {
            $table->string('resistencia_fc', 20)->default('')->after('elemento');
        });

        foreach (DB::table('resistencias')->get(['id', 'nombre']) as $resistencia) {
            DB::table('partidas')->where('resistencia_id', $resistencia->id)->update(['resistencia_fc' => $resistencia->nombre]);
        }

        Schema::table('partidas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resistencia_id');
        });
    }
};
