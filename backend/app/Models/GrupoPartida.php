<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Catálogo global de grupos del presupuesto (Cimentación, Cisterna…). */
#[Fillable(['nombre', 'partida_control', 'orden'])]
class GrupoPartida extends Model
{
    protected $table = 'grupos_partida';

    protected function casts(): array
    {
        return ['orden' => 'integer'];
    }

    public function partidas(): HasMany
    {
        return $this->hasMany(Partida::class, 'grupo_id');
    }

    /** Los elementos estructurales que pueden elegir las partidas de este grupo. */
    public function elementos(): BelongsToMany
    {
        return $this->belongsToMany(ElementoEstructural::class, 'grupo_elemento', 'grupo_id', 'elemento_id')
            ->orderBy('elementos_estructurales.id');
    }

    /** Si alguna partida del grupo usa ese elemento (la partida guarda el nombre, no el id). */
    public function usaElemento(string $nombre): bool
    {
        return $this->partidas()->where('elemento', $nombre)->exists();
    }
}
