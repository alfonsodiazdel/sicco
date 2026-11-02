<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Producto de una concretera, identificado por el código que imprime en sus guías. */
#[Fillable(['proveedor_id', 'codigo', 'codigo_material', 'descripcion', 'resistencia_id'])]
class ProductoProveedor extends Model
{
    protected $table = 'productos_proveedor';

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function resistencia(): BelongsTo
    {
        return $this->belongsTo(Resistencia::class);
    }
}
