<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyectos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 120);
            $table->string('direccion', 150);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->foreignId('ingeniero_id')->constrained('users')->restrictOnDelete();
            $table->string('estado', 20)->default('Activo');
            $table->timestamps();
        });

        Schema::create('usuario_proyecto', function (Blueprint $table) {
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->primary(['usuario_id', 'proyecto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_proyecto');
        Schema::dropIfExists('proyectos');
    }
};
