<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Elemento estructural que se elige en una partida (Zapata, Columna, Viga…). */
#[Fillable(['nombre', 'activo'])]
class ElementoEstructural extends Model
{
    protected $table = 'elementos_estructurales';

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** `partidas.elemento` guarda el nombre como texto, no una llave foránea. */
    public function enUso(): bool
    {
        return Partida::where('elemento', $this->nombre)->exists();
    }
}
