<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CU05: el sector culminado ya no recibe concreto. Con la fecha, el reporte EAT (CU15) sabe si
        // lo estaba a la fecha de corte: su vaciado es costo real; el de los abiertos se proyecta.
        Schema::table('sectores', function (Blueprint $table) {
            $table->date('fecha_culminacion')->nullable()->after('volumen_vaciado_m3');
            $table->foreignId('culminado_por')->nullable()->after('fecha_culminacion')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sectores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('culminado_por');
            $table->dropColumn('fecha_culminacion');
        });
    }
};
