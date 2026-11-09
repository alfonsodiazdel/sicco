<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CU11: factura del proveedor de concreto, con su archivo (imagen o PDF).
        Schema::create('facturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->string('numero', 20);
            $table->date('fecha');
            // Suma de las líneas: CU11 paso 5 lo compara con el volumen de las guías (arquitectura §6.4).
            $table->decimal('volumen_m3', 12, 2);
            // Sin IGV: es el costo real del proyecto, comparable con el presupuesto.
            $table->decimal('subtotal', 12, 2);
            $table->decimal('igv', 12, 2);
            $table->decimal('total', 12, 2);
            // CU11 flujo alternativo 1: se registró aunque el volumen no coincidía con las guías.
            $table->boolean('diferencia_confirmada')->default(false);
            // Ruta en el almacenamiento de objetos (bucket privado).
            $table->string('archivo_ruta');
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // CU11 flujo alternativo 2: «La factura ya fue registrada». La numeración es de cada proveedor.
            $table->unique(['proveedor_id', 'numero']);
            $table->index(['proyecto_id', 'fecha']);
        });

        // Detalle de la factura por resistencia, como lo imprimen las concreteras: f'c, m³ y precio.
        // El precio unitario da el importe de cada guía vinculada (CU12 paso 3).
        Schema::create('factura_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();
            $table->foreignId('resistencia_id')->constrained('resistencias')->restrictOnDelete();
            $table->decimal('volumen_m3', 12, 2);
            // Sin IGV, por m³.
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('importe', 12, 2);
            $table->timestamps();

            $table->unique(['factura_id', 'resistencia_id']);
        });

        // CU12: la guía queda «Facturada» al vincularse. Requerimiento especial: una sola factura por guía.
        Schema::table('guias_remision', function (Blueprint $table) {
            $table->foreignId('factura_id')->nullable()->after('estado_asignacion')->constrained('facturas')->restrictOnDelete();
            $table->index(['proyecto_id', 'proveedor_id', 'factura_id']);
        });
    }

    public function down(): void
    {
        Schema::table('guias_remision', function (Blueprint $table) {
            $table->dropIndex(['proyecto_id', 'proveedor_id', 'factura_id']);
            $table->dropConstrainedForeignId('factura_id');
        });
        Schema::dropIfExists('factura_lineas');
        Schema::dropIfExists('facturas');
    }
};
