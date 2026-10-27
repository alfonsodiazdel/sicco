<?php

namespace App\Models;

use App\Enums\Rol as RolEnum;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['rol_id', 'nombres', 'apellidos', 'email', 'password', 'activo', 'debe_cambiar_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activo' => 'boolean',
            'debe_cambiar_password' => 'boolean',
            'ultimo_acceso_at' => 'datetime',
        ];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    protected function nombreCompleto(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->nombres} {$this->apellidos}"));
    }

    public function tieneRol(RolEnum ...$roles): bool
    {
        return in_array($this->rol?->clave, $roles, true);
    }
}
