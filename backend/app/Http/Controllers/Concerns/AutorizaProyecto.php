<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\Rol;
use App\Models\Proyecto;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Restringe a los proyectos asignados al usuario (USUARIO_PROYECTO), como el selector del
 * encabezado. El administrador consulta todos; el rol lo filtra antes el middleware de la ruta.
 */
trait AutorizaProyecto
{
    protected function autorizarProyecto(Request $request, Proyecto $proyecto): void
    {
        $usuario = $request->user();
        if ($usuario->tieneRol(Rol::Administrador)
            || $proyecto->usuarios()->whereKey($usuario->id)->exists()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => __('sicco.proyectos.sin_acceso'),
            'code' => 'sin_permiso',
        ], Response::HTTP_FORBIDDEN));
    }
}
