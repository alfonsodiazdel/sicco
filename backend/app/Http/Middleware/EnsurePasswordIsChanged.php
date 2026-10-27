<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Obliga a cambiar la contraseña temporal enviada por correo (CU02) antes de usar el sistema.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->debe_cambiar_password) {
            return response()->json([
                'message' => __('sicco.auth.debe_cambiar_password'),
                'code' => 'debe_cambiar_password',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
