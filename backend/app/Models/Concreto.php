<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Concreto que usa un proyecto, con su precio unitario sin IGV. Las partidas del presupuesto con
 * el mismo concreto comparten ese precio. Hoy se distingue solo por su resistencia f'c; más
 * adelante podrá llevar aditivos.
 */
#[Fillable(['proyecto_id', 'resistencia_id', 'precio_unitario'])]
class Concreto extends Model
{
    protected function casts(): array
    {
        return ['precio_unitario' => 'decimal:2'];
    }

    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function resistencia(): BelongsTo
    {
        return $this->belongsTo(Resistencia::class);
    }

    public function partidas(): HasMany
    {
        return $this->hasMany(Partida::class);
    }

    /** El concreto del proyecto con esa resistencia, con el precio dado; lo crea si no existe. */
    public static function fijar(int $proyectoId, int $resistenciaId, float|string $precio): self
    {
        return self::updateOrCreate(
            ['proyecto_id' => $proyectoId, 'resistencia_id' => $resistenciaId],
            ['precio_unitario' => $precio],
        );
    }
}
