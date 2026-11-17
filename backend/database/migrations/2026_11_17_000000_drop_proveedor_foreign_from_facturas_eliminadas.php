<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `facturas_eliminadas` es un registro: su `proveedor_id` queda como dato, igual que `factura_id`,
     * para que una factura eliminada no impida eliminar al proveedor. La razón social y el RUC van en
     * `datos.proveedor`, así el registro se entiende aunque el proveedor ya no exista.
     */
    public function up(): void
    {
        Schema::table('facturas_eliminadas', function (Blueprint $table) {
            $table->dropForeign(['proveedor_id']);
        });
    }

    /** Falla si algún registro apunta a un proveedor ya eliminado. */
    public function down(): void
    {
        Schema::table('facturas_eliminadas', function (Blueprint $table) {
            $table->foreign('proveedor_id')->references('id')->on('proveedores')->restrictOnDelete();
        });
    }
};
