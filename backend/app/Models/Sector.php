<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sector de vaciado de un nivel; toma parte del metrado de una partida del presupuesto (CU05). */
#[Fillable(['nivel_id', 'presupuesto_id', 'nombre', 'volumen_presup_m3'])]
class Sector extends Model
{
    protected $table = 'sectores';

    protected function casts(): array
    {
        return [
            'volumen_presup_m3' => 'decimal:2',
            'volumen_vaciado_m3' => 'decimal:2',
            'fecha_culminacion' => 'immutable_date',
        ];
    }

    /** Culminado: ya no recibe guías ni cambia; se reabre para corregirlo. */
    public function culminado(): bool
    {
        return $this->fecha_culminacion !== null;
    }

    public function nivel(): BelongsTo
    {
        return $this->belongsTo(Nivel::class);
    }

    public function partida(): BelongsTo
    {
        return $this->belongsTo(Partida::class, 'presupuesto_id');
    }
}
