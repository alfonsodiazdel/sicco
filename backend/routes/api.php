<?php

use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SesionController;
use App\Http\Controllers\Api\V1\ProyectoController;
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
                Route::get('proyectos/opciones', [ProyectoController::class, 'opciones']);
            });

            // CU03 Gestionar proyectos
            Route::get('proyectos/asignados', [ProyectoController::class, 'asignados']);
            // El administrador consulta todos los módulos sin modificarlos.
            Route::get('proyectos', [ProyectoController::class, 'index'])->middleware('rol:oficina_tecnica,administrador');
            Route::middleware('rol:oficina_tecnica')->group(function () {
                Route::post('proyectos', [ProyectoController::class, 'store']);
                Route::put('proyectos/{proyecto}', [ProyectoController::class, 'update'])->whereNumber('proyecto');
                Route::get('proyectos/ingenieros', [ProyectoController::class, 'ingenieros']);
            });
        });
    });
});
