<?php

namespace App\Models;

use App\Enums\EstadoAlerta;
use App\Enums\Rol;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un sector pasó lo presupuestado más su desperdicio (ALERTA, CU14). */
#[Fillable(['proyecto_id', 'sector_id', 'exceso_m3', 'fecha', 'estado', 'revisada_por', 'revisada_at', 'correo_enviado_at'])]
class Alerta extends Model
{
    protected function casts(): array
    {
        return [
            'exceso_m3' => 'decimal:2',
            'fecha' => 'datetime',
            'estado' => EstadoAlerta::class,
            'revisada_at' => 'datetime',
            'correo_enviado_at' => 'datetime',
        ];
    }

    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisada_por');
    }

    /** @param  Builder<Alerta>  $query */
    public function scopePendientes(Builder $query): void
    {
        $query->where('estado', EstadoAlerta::Pendiente);
    }

    /** Alertas de los proyectos del usuario (USUARIO_PROYECTO); el administrador ve todas. */
    public function scopeVisiblesPara(Builder $query, User $usuario): void
    {
        if (! $usuario->tieneRol(Rol::Administrador)) {
            $query->whereIn('proyecto_id', fn ($q) => $q->select('proyecto_id')
                ->from('usuario_proyecto')->where('usuario_id', $usuario->id));
        }
    }
}
