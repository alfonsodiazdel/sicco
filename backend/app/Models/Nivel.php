<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Nivel (piso) del proyecto donde se vacía concreto (CU05). */
#[Fillable(['proyecto_id', 'nombre', 'orden'])]
class Nivel extends Model
{
    protected $table = 'niveles';

    protected function casts(): array
    {
        return ['orden' => 'integer'];
    }

    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function sectores(): HasMany
    {
        return $this->hasMany(Sector::class);
    }
}
