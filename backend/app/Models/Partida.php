<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * Partida del presupuesto de concreto de un proyecto (PRESUPUESTO, CU04). Su resistencia y su
 * precio unitario los da su concreto, que comparte con las demás partidas del mismo concreto.
 */
#[Fillable([
    'proyecto_id', 'grupo_id', 'item', 'descripcion', 'elemento', 'concreto_id',
    'metrado_m3', 'factor_desperdicio',
])]
class Partida extends Model
{
    protected function casts(): array
    {
        return [
            'metrado_m3' => 'decimal:2',
            'factor_desperdicio' => 'decimal:2',
        ];
    }

    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function concreto(): BelongsTo
    {
        return $this->belongsTo(Concreto::class);
    }

    /** La resistencia de su concreto. */
    public function resistencia(): HasOneThrough
    {
        return $this->hasOneThrough(Resistencia::class, Concreto::class, 'id', 'id', 'concreto_id', 'resistencia_id');
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
