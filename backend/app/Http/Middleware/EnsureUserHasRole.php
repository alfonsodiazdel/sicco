<?php

namespace App\Http\Middleware;

use App\Enums\Rol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a uno o más roles.
 * Uso: ->middleware('rol:oficina_tecnica,gerencia')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $permitidos = array_map(fn (string $rol) => Rol::from($rol), $roles);

        if (! $request->user()?->tieneRol(...$permitidos)) {
            return response()->json([
                'message' => __('sicco.auth.sin_permiso'),
                'code' => 'sin_permiso',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
