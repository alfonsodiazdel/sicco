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

    /** Si algún concreto de un proyecto, guía o línea de factura la usa (por `resistencia_id`). */
    public function enUso(): bool
    {
        return Concreto::where('resistencia_id', $this->id)->exists()
            || GuiaRemision::where('resistencia_id', $this->id)->exists()
            || FacturaLinea::where('resistencia_id', $this->id)->exists();
    }
}
