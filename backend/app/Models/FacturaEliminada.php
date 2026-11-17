<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de una factura eliminada: quién, cuándo, por qué y una copia de lo que tenía. */
#[Fillable([
    'factura_id', 'proyecto_id', 'proveedor_id', 'numero', 'fecha', 'total', 'motivo', 'datos',
    'registrado_por', 'eliminado_por',
])]
class FacturaEliminada extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'facturas_eliminadas';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'total' => 'decimal:2',
            'datos' => 'array',
        ];
    }

    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function eliminadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eliminado_por');
    }
}
