<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CU09: volumen de una guía vaciado en un sector. Una guía puede repartirse en varios sectores.
        Schema::create('asignaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guia_id')->constrained('guias_remision')->restrictOnDelete();
            $table->foreignId('sector_id')->constrained('sectores')->restrictOnDelete();
            $table->decimal('volumen_m3', 12, 2);
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['guia_id', 'sector_id']);
        });

        // CU09 requerimiento especial: historial de cambios de cada asignación. Cada guardado deja
        // el reparto anterior y el nuevo de la guía.
        Schema::create('historial_asignaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guia_id')->constrained('guias_remision')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->json('antes');
            $table->json('despues');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_asignaciones');
        Schema::dropIfExists('asignaciones');
    }
};
