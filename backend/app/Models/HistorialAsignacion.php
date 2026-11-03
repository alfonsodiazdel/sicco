<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un guardado de la asignación de una guía: el reparto anterior y el nuevo (CU09). */
#[Fillable(['guia_id', 'usuario_id', 'antes', 'despues'])]
class HistorialAsignacion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'historial_asignaciones';

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
