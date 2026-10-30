<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de la pantalla de usuarios. Los proyectos llegan ya cargados en bloque,
 * porque User no tiene la relación con USUARIO_PROYECTO.
 *
 * @mixin User
 */
class UsuarioResource extends JsonResource
{
    /** @param  list<int>  $proyectoIds */
    public function __construct(User $usuario, private array $proyectoIds)
    {
        parent::__construct($usuario);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'email' => $this->email,
            'rol' => $this->rol->clave,
            'activo' => $this->activo,
            'proyecto_ids' => $this->proyectoIds,
        ];
    }
}
