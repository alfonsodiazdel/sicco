<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lectura de la factura con OCR, como en las guías (arquitectura §3.6): de dónde vienen los
        // datos y la respuesta tal cual, para auditar y medir la precisión.
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('origen', 10)->default('manual')->after('diferencia_confirmada');
            $table->json('ocr_payload')->nullable()->after('origen');
            $table->decimal('ocr_confianza', 3, 2)->nullable()->after('ocr_payload');
        });

        // Lo que cobra la factura y no es concreto (bombeo, aditivos, tiempo de espera…): suma al
        // costo real, pero no entra en la comparación de volúmenes con las guías.
        Schema::create('factura_cargos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();
            $table->string('descripcion', 200);
            // Sin IGV.
            $table->decimal('importe', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factura_cargos');
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn(['origen', 'ocr_payload', 'ocr_confianza']);
        });
    }
};
