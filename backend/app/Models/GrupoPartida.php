<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
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
}
