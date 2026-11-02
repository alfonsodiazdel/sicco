<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CU05: niveles (pisos) del proyecto.
        Schema::create('niveles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->string('nombre', 40);
            // De abajo hacia arriba: Sótano 2, Sótano 1, Piso 1…
            $table->unsignedSmallInteger('orden');
            $table->timestamps();

            $table->unique(['proyecto_id', 'nombre']);
        });

        // CU05: sectores de vaciado de cada nivel. Cada uno toma parte del metrado de una partida.
        Schema::create('sectores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nivel_id')->constrained('niveles')->restrictOnDelete();
            // Una partida repartida en sectores no se elimina.
            $table->foreignId('presupuesto_id')->constrained('partidas')->restrictOnDelete();
            $table->string('nombre', 40);
            $table->decimal('volumen_presup_m3', 12, 2);
            // Dato desnormalizado: lo suma la asignación de guías (CU09) en la misma transacción.
            $table->decimal('volumen_vaciado_m3', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['nivel_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sectores');
        Schema::dropIfExists('niveles');
    }
};
