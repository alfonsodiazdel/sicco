<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRESUPUESTO (CU04): cada fila del presupuesto de concreto de un proyecto.
        Schema::create('partidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            // Un grupo con partidas no se elimina.
            $table->foreignId('grupo_id')->constrained('grupos_partida')->restrictOnDelete();
            $table->string('item', 20)->nullable();
            $table->string('descripcion', 200);
            $table->string('elemento', 30);
            $table->string('resistencia_fc', 20);
            $table->decimal('metrado_m3', 12, 2);
            // En porcentaje: 5.00 es 5 %.
            $table->decimal('factor_desperdicio', 5, 2);
            $table->decimal('precio_unitario', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partidas');
    }
};
