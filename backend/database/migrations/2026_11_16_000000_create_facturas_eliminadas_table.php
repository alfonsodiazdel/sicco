<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de las facturas eliminadas (p. ej. registradas en el proyecto equivocado). La
        // factura se borra de verdad para liberar su número y sus guías; aquí queda quién la borró,
        // cuándo, por qué y una copia de lo que tenía.
        Schema::create('facturas_eliminadas', function (Blueprint $table) {
            $table->id();
            // Sin clave foránea: la factura ya no existe.
            $table->unsignedBigInteger('factura_id');
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->string('numero', 20);
            $table->date('fecha');
            $table->decimal('total', 12, 2);
            $table->string('motivo', 500)->nullable();
            // La factura tal como estaba: cabecera, líneas, cargos y guías que cobraba.
            $table->json('datos');
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('eliminado_por')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['proyecto_id', 'created_at']);
            $table->index(['proveedor_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas_eliminadas');
    }
};
