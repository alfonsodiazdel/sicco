<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Productos de cada concretera tal como los imprimen sus guías y facturas, con la resistencia
        // a la que corresponden. Con ellos la resistencia de una guía se resuelve por el código, sin
        // depender de cómo la escriba cada concretera.
        Schema::create('productos_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            // El código que trae la guía, p. ej. 70280NB5746 (Holcim) o 22259.
            $table->string('codigo', 30);
            // Código interno del material, si la concretera imprime otro además (Holcim: 10088930).
            $table->string('codigo_material', 30)->nullable();
            $table->string('descripcion', 200)->nullable();
            $table->foreignId('resistencia_id')->constrained('resistencias')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['proveedor_id', 'codigo']);
        });

        Schema::table('proveedores', function (Blueprint $table) {
            // Para un código que aún no está en el catálogo: expresión regular con el grupo «fc»,
            // p. ej. ^70(?<fc>\d{3}) para Holcim (70280NB5746 es f'c 280).
            $table->string('patron_codigo', 100)->nullable()->after('razon_social');
            // Dónde está la resistencia en las guías de esta concretera; se agrega al prompt del OCR.
            $table->text('indicaciones_ocr')->nullable()->after('patron_codigo');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn(['patron_codigo', 'indicaciones_ocr']);
        });
        Schema::dropIfExists('productos_proveedor');
    }
};
