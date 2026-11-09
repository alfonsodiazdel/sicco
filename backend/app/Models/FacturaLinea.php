<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de la factura: el concreto de una resistencia, con su volumen y precio unitario sin IGV. */
#[Fillable(['factura_id', 'resistencia_id', 'volumen_m3', 'precio_unitario', 'importe'])]
class FacturaLinea extends Model
{
    protected $table = 'factura_lineas';

    protected function casts(): array
    {
        return [
            'volumen_m3' => 'decimal:2',
            'precio_unitario' => 'decimal:2',
            'importe' => 'decimal:2',
        ];
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    public function resistencia(): BelongsTo
    {
        return $this->belongsTo(Resistencia::class);
    }
}
