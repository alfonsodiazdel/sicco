<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CambiarPasswordRequest;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function update(CambiarPasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => $request->validated('password'),
            'debe_cambiar_password' => false,
        ]);

        return response()->json(['message' => __('sicco.auth.password_actualizada')]);
    }
}
