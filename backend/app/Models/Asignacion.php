<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Volumen de una guía vaciado en un sector (ASIGNACION, CU09). */
#[Fillable(['guia_id', 'sector_id', 'volumen_m3', 'registrado_por'])]
class Asignacion extends Model
{
    protected $table = 'asignaciones';

    protected function casts(): array
    {
        return ['volumen_m3' => 'decimal:2'];
    }

    public function guia(): BelongsTo
    {
        return $this->belongsTo(GuiaRemision::class, 'guia_id');
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}
