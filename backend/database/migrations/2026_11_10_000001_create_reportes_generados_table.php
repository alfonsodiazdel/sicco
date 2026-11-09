<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CU15/CU16: cada reporte generado en pantalla o descargado, con quién y con qué parámetros.
        Schema::create('reportes_generados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            // eat | corte_mes
            $table->string('tipo', 20);
            $table->json('parametros');
            // pantalla | excel | pdf
            $table->string('formato', 20);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reportes_generados');
    }
};
