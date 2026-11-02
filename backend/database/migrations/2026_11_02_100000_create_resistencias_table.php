<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de resistencias f'c: lo comparten el presupuesto (CU04) y las guías (CU06), para
        // compararlas al asignar guías a sectores y en los reportes. Valores iniciales: ResistenciaSeeder.
        Schema::create('resistencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fc_kg_cm2')->unique();
            // Como se muestra: «f'c 280 kg/cm²».
            $table->string('nombre', 30);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resistencias');
    }
};
