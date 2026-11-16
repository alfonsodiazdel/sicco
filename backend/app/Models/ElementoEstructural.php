<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Elemento estructural que se elige en una partida (Zapata, Columna, Viga…). */
#[Fillable(['nombre', 'activo'])]
class ElementoEstructural extends Model
{
    protected $table = 'elementos_estructurales';

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** Los grupos en los que se puede elegir este elemento. */
    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(GrupoPartida::class, 'grupo_elemento', 'elemento_id', 'grupo_id')
            ->orderBy('grupos_partida.orden')->orderBy('grupos_partida.id');
    }

    /** `partidas.elemento` guarda el nombre como texto, no una llave foránea. */
    public function enUso(): bool
    {
        return Partida::where('elemento', $this->nombre)->exists();
    }
}
