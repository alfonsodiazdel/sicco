<?php

namespace App\Models;

use App\Enums\EstadoAsignacion;
use App\Enums\OrigenGuia;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Guía de remisión del concreto recibido en obra (CU06–CU08). */
#[Fillable([
    'proyecto_id', 'proveedor_id', 'numero', 'fecha', 'resistencia_id', 'producto_id', 'volumen_m3', 'placa',
    'foto_ruta', 'estado_asignacion', 'origen', 'ocr_payload', 'ocr_confianza', 'registrado_por',
])]
class GuiaRemision extends Model
{
    protected $table = 'guias_remision';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'volumen_m3' => 'decimal:2',
            'estado_asignacion' => EstadoAsignacion::class,
            'origen' => OrigenGuia::class,
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

    public function resistencia(): BelongsTo
    {
        return $this->belongsTo(Resistencia::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoProveedor::class, 'producto_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /** Sectores en los que se vació su volumen (CU09). */
    public function asignaciones(): HasMany
    {
        return $this->hasMany(Asignacion::class, 'guia_id');
    }

    public function historialAsignaciones(): HasMany
    {
        return $this->hasMany(HistorialAsignacion::class, 'guia_id');
    }
}
