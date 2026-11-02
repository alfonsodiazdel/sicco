<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Resistencia f'c del concreto, en kg/cm² (catálogo compartido por el presupuesto y las guías). */
#[Fillable(['fc_kg_cm2', 'nombre', 'activo'])]
class Resistencia extends Model
{
    protected $table = 'resistencias';

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** La resistencia activa de ese f'c, si está en el catálogo. */
    public static function deFc(?int $fc): ?self
    {
        return $fc ? self::where('fc_kg_cm2', $fc)->where('activo', true)->first() : null;
    }
}
