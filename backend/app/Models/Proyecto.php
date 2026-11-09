<?php

namespace App\Models;

use App\Enums\EstadoProyecto;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['codigo', 'nombre', 'direccion', 'fecha_inicio', 'fecha_fin', 'ingeniero_id', 'estado'])]
class Proyecto extends Model
{
    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'estado' => EstadoProyecto::class,
        ];
    }

    public function ingeniero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ingeniero_id');
    }

    /** Usuarios con acceso al proyecto (USUARIO_PROYECTO). */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'usuario_proyecto', 'proyecto_id', 'usuario_id');
    }

    /** Partidas de su presupuesto de concreto (CU04). */
    public function partidas(): HasMany
    {
        return $this->hasMany(Partida::class);
    }

    /** Niveles (pisos) donde se reparte el presupuesto en sectores (CU05). */
    public function niveles(): HasMany
    {
        return $this->hasMany(Nivel::class);
    }

    /** Guías de remisión del concreto recibido en la obra (CU06). */
    public function guias(): HasMany
    {
        return $this->hasMany(GuiaRemision::class);
    }

    /** Facturas de concreto del proyecto (CU11): su subtotal es el costo real. */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }
}
