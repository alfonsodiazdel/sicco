<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Partida del presupuesto de concreto de un proyecto (PRESUPUESTO, CU04). */
#[Fillable([
    'proyecto_id', 'grupo_id', 'item', 'descripcion', 'elemento', 'resistencia_fc',
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

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoPartida::class, 'grupo_id');
    }
}
