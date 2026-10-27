<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * CU01 Iniciar sesión.
 */
class SesionController extends Controller
{
    public function store(LoginRequest $request): JsonResponse|UserResource
    {
        $request->authenticate();

        $user = $request->user();

        if (! $user->activo) {
            $this->cerrarSesion($request);

            return response()->json([
                'message' => __('sicco.auth.inactivo'),
                'code' => 'usuario_inactivo',
            ], Response::HTTP_FORBIDDEN);
        }

        $request->session()->regenerate();
        $user->forceFill(['ultimo_acceso_at' => now()])->save();

        return UserResource::make($user->load('rol'));
    }

    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user()->load('rol'));
    }

    public function destroy(Request $request): Response
    {
        $this->cerrarSesion($request);

        return response()->noContent();
    }

    private function cerrarSesion(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
