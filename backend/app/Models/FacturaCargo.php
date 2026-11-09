<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lo que cobra la factura y no es concreto, p. ej. el bombeo: suma al costo, no al volumen. */
#[Fillable(['factura_id', 'descripcion', 'importe'])]
class FacturaCargo extends Model
{
    protected $table = 'factura_cargos';

    protected function casts(): array
    {
        return ['importe' => 'decimal:2'];
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }
}
