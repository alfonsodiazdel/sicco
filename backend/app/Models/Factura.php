<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Factura del proveedor de concreto, vinculada a las guías que cobra (CU11/CU12). */
#[Fillable([
    'proyecto_id', 'proveedor_id', 'numero', 'fecha', 'volumen_m3', 'subtotal', 'igv', 'total',
    'diferencia_confirmada', 'origen', 'ocr_payload', 'ocr_confianza', 'archivo_ruta', 'registrado_por',
])]
class Factura extends Model
{
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'volumen_m3' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
            'diferencia_confirmada' => 'boolean',
            'ocr_payload' => 'array',
            'ocr_confianza' => 'decimal:2',
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

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /** Detalle por resistencia: m³ y precio unitario. */
    public function lineas(): HasMany
    {
        return $this->hasMany(FacturaLinea::class);
    }

    /** Lo que cobra y no es concreto (bombeo, aditivos…). */
    public function cargos(): HasMany
    {
        return $this->hasMany(FacturaCargo::class);
    }

    /** Guías que cobra (CU12). */
    public function guias(): HasMany
    {
        return $this->hasMany(GuiaRemision::class);
    }
}
