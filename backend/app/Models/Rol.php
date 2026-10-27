<?php

namespace App\Models;

use App\Enums\Rol as RolEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['clave', 'nombre', 'descripcion'])]
class Rol extends Model
{
    protected $table = 'roles';

    protected function casts(): array
    {
        return [
            'clave' => RolEnum::class,
        ];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'rol_id');
    }
}
