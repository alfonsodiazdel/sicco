<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CU14: aviso de que un sector pasó lo presupuestado más su desperdicio. Además de lo que
        // pide el DER (sector, exceso, fecha, estado) guarda quién la revisó y cuándo salió el
        // correo (arquitectura §6.6), y el proyecto para listar sin unir con niveles.
        Schema::create('alertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->foreignId('sector_id')->constrained('sectores')->restrictOnDelete();
            $table->decimal('exceso_m3', 12, 2);
            $table->dateTime('fecha');
            $table->string('estado', 20);
            $table->foreignId('revisada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('revisada_at')->nullable();
            $table->dateTime('correo_enviado_at')->nullable();
            $table->timestamps();

            $table->index(['sector_id', 'estado']);
            $table->index(['proyecto_id', 'estado', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
    }
};
