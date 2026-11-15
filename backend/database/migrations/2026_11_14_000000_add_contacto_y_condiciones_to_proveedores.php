<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('contacto_nombre', 150)->nullable()->after('razon_social');
            $table->char('contacto_telefono', 9)->nullable()->after('contacto_nombre');
            $table->string('contacto_correo')->nullable()->after('contacto_telefono');
            $table->string('correo_facturacion')->nullable()->after('contacto_correo');
            $table->string('condicion_pago', 10)->nullable()->after('correo_facturacion');
            $table->string('direccion_fiscal', 250)->nullable()->after('condicion_pago');
            $table->string('planta_despacho', 150)->nullable()->after('direccion_fiscal');
            $table->boolean('activo')->default(true)->after('planta_despacho');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn([
                'contacto_nombre', 'contacto_telefono', 'contacto_correo', 'correo_facturacion',
                'condicion_pago', 'direccion_fiscal', 'planta_despacho', 'activo',
            ]);
        });
    }
};
