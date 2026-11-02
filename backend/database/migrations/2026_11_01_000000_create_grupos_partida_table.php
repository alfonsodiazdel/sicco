<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo global: los mismos grupos en todos los proyectos, para comparar obras en los reportes.
        Schema::create('grupos_partida', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique();
            // Código de control de costos de la empresa (2.02.02 = concreto premezclado).
            $table->string('partida_control', 20)->default('2.02.02');
            $table->unsignedSmallInteger('orden');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos_partida');
    }
};
