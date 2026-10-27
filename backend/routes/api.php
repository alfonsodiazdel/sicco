<?php

use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SesionController;
use App\Http\Controllers\Api\V1\RolController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // CU01 Iniciar sesión
    Route::post('auth/login', [SesionController::class, 'store']);

    Route::middleware(['auth:sanctum', 'activo'])->group(function () {
        Route::get('auth/me', [SesionController::class, 'show']);
        Route::post('auth/logout', [SesionController::class, 'destroy']);
        Route::put('auth/password', [PasswordController::class, 'update']);

        // Rutas del sistema: requieren haber cambiado la contraseña temporal.
        Route::middleware('password.cambiado')->group(function () {
            Route::middleware('rol:administrador')->group(function () {
                Route::get('roles', [RolController::class, 'index']);
            });
        });
    });
});
