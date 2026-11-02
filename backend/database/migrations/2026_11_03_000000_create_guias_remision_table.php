<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CU06–CU08: guía de remisión del concreto recibido en obra, con su foto.
        Schema::create('guias_remision', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->string('numero', 20);
            $table->date('fecha');
            $table->foreignId('resistencia_id')->constrained('resistencias')->restrictOnDelete();
            // El producto de la concretera, si la guía trae su código (CU07 lo lee).
            $table->foreignId('producto_id')->nullable()->constrained('productos_proveedor')->nullOnDelete();
            $table->decimal('volumen_m3', 12, 2);
            $table->string('placa', 10)->nullable();
            // Ruta en el almacenamiento de objetos (bucket privado, se ve con URL firmada).
            $table->string('foto_ruta');
            $table->string('estado_asignacion', 20)->default('sin_sector')->index();
            $table->string('origen', 10);
            // Respuesta del OCR tal cual, para auditar y medir su precisión (arquitectura §3.6).
            $table->json('ocr_payload')->nullable();
            // La menor confianza entre los campos leídos.
            $table->decimal('ocr_confianza', 3, 2)->nullable();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // CU06/CU08: «La guía ya fue registrada». La numeración es propia de cada concretera.
            $table->unique(['proveedor_id', 'numero']);
            $table->index(['proyecto_id', 'estado_asignacion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guias_remision');
    }
};
