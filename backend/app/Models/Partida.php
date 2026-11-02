<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Partida del presupuesto de concreto de un proyecto (PRESUPUESTO, CU04). */
#[Fillable([
    'proyecto_id', 'grupo_id', 'item', 'descripcion', 'elemento', 'resistencia_id',
    'metrado_m3', 'factor_desperdicio', 'precio_unitario',
])]
class Partida extends Model
{
    protected function casts(): array
    {
        return [
            'metrado_m3' => 'decimal:2',
            'factor_desperdicio' => 'decimal:2',
            'precio_unitario' => 'decimal:2',
        ];
    }

    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function resistencia(): BelongsTo
    {
        return $this->belongsTo(Resistencia::class);
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoPartida::class, 'grupo_id');
    }

    /** Sectores de vaciado entre los que se reparte su metrado (CU05). */
    public function sectores(): HasMany
    {
        return $this->hasMany(Sector::class, 'presupuesto_id');
    }
}
