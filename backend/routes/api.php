<?php

use App\Http\Controllers\Api\V1\AsignacionController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SesionController;
use App\Http\Controllers\Api\V1\GrupoPartidaController;
use App\Http\Controllers\Api\V1\GuiaController;
use App\Http\Controllers\Api\V1\GuiaSinSectorController;
use App\Http\Controllers\Api\V1\NivelController;
use App\Http\Controllers\Api\V1\PartidaController;
use App\Http\Controllers\Api\V1\ProveedorController;
use App\Http\Controllers\Api\V1\ProyectoController;
use App\Http\Controllers\Api\V1\ResistenciaController;
use App\Http\Controllers\Api\V1\RolController;
use App\Http\Controllers\Api\V1\SectorController;
use App\Http\Controllers\Api\V1\UsuarioController;
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

            // Catálogo de resistencias f'c: lo usan el presupuesto (CU04) y el registro de guías (CU06).
            Route::get('resistencias', [ResistenciaController::class, 'index']);

            // Catálogo global de grupos de partidas: lo mantiene Oficina técnica.
            Route::get('grupos-partida', [GrupoPartidaController::class, 'index'])->middleware('rol:oficina_tecnica,administrador');
            Route::middleware('rol:oficina_tecnica')->group(function () {
                Route::post('grupos-partida', [GrupoPartidaController::class, 'store']);
                Route::put('grupos-partida/{grupo}', [GrupoPartidaController::class, 'update'])->whereNumber('grupo');
                Route::delete('grupos-partida/{grupo}', [GrupoPartidaController::class, 'destroy'])->whereNumber('grupo');
            });

            // CU04 Presupuesto: lo consultan también Producción (para repartirlo en sectores, CU05) y
            // Gerencia; solo de los proyectos asignados.
            Route::get('proyectos/{proyecto}/partidas', [PartidaController::class, 'index'])
                ->whereNumber('proyecto')
                ->middleware('rol:oficina_tecnica,ingeniero_produccion,gerencia,administrador');
            Route::middleware('rol:oficina_tecnica')->group(function () {
                Route::post('proyectos/{proyecto}/partidas', [PartidaController::class, 'store'])->whereNumber('proyecto');
                Route::put('partidas/{partida}', [PartidaController::class, 'update'])->whereNumber('partida');
            });

            // CU05 Niveles y sectores de vaciado: los gestiona Producción y los consultan también
            // Oficina técnica y Gerencia; solo de los proyectos asignados.
            Route::middleware('rol:ingeniero_produccion,oficina_tecnica,gerencia,administrador')->group(function () {
                Route::get('proyectos/{proyecto}/niveles', [NivelController::class, 'index'])->whereNumber('proyecto');
                Route::get('proyectos/{proyecto}/sectores', [SectorController::class, 'index'])->whereNumber('proyecto');
            });
            Route::middleware('rol:ingeniero_produccion')->group(function () {
                Route::post('proyectos/{proyecto}/niveles', [NivelController::class, 'store'])->whereNumber('proyecto');
                Route::put('niveles/{nivel}', [NivelController::class, 'update'])->whereNumber('nivel');
                Route::post('niveles/{nivel}/sectores', [SectorController::class, 'store'])->whereNumber('nivel');
                Route::put('sectores/{sector}', [SectorController::class, 'update'])->whereNumber('sector');
            });

            // CU06–CU08 Registrar guía de remisión: el almacenero, desde el celular, en sus proyectos.
            Route::middleware('rol:almacenero')->group(function () {
                // CU07: cada lectura cuesta en la API de IA.
                Route::post('guias/lecturas', [GuiaController::class, 'lectura'])->middleware('throttle:ocr');
                Route::post('guias', [GuiaController::class, 'store']);
            });

            // Foto de la guía: la ve todo rol que consulta guías, solo en sus proyectos.
            Route::get('guias/{guia}/foto', [GuiaController::class, 'foto'])
                ->whereNumber('guia')
                ->middleware('rol:administrador,almacenero,ingeniero_produccion,oficina_tecnica,gerencia');

            // CU10 Guías sin sector: las consultan Producción y Oficina técnica en sus proyectos, y
            // CU09 la asignación que se abre desde ellas, que solo guarda Producción.
            Route::middleware('rol:ingeniero_produccion,oficina_tecnica,administrador')->group(function () {
                Route::get('proyectos/{proyecto}/guias/sin-sector', [GuiaSinSectorController::class, 'index'])->whereNumber('proyecto');
                Route::get('proyectos/{proyecto}/guias/sin-sector/exportar', [GuiaSinSectorController::class, 'exportar'])->whereNumber('proyecto');
                Route::get('guias/{guia}/asignaciones', [AsignacionController::class, 'show'])->whereNumber('guia');
            });
            Route::put('guias/{guia}/asignaciones', [AsignacionController::class, 'update'])
                ->whereNumber('guia')
                ->middleware('rol:ingeniero_produccion');

            // CU02 Gestionar usuarios y roles
            Route::middleware('rol:administrador')->group(function () {
                Route::get('usuarios', [UsuarioController::class, 'index']);
                Route::post('usuarios', [UsuarioController::class, 'store']);
                Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->whereNumber('usuario');
                Route::patch('usuarios/{usuario}/estado', [UsuarioController::class, 'estado'])->whereNumber('usuario');
            });

            // Proveedores (concreteras): los consultan todos los roles (el administrador ve todo)
            // y los gestiona Oficina técnica.
            Route::middleware('rol:administrador,almacenero,ingeniero_produccion,oficina_tecnica,gerencia')->group(function () {
                Route::apiResource('proveedores', ProveedorController::class)
                    ->only(['index', 'show'])
                    ->parameters(['proveedores' => 'proveedor']);
            });
            Route::middleware('rol:oficina_tecnica')->group(function () {
                Route::apiResource('proveedores', ProveedorController::class)
                    ->only(['store', 'update', 'destroy'])
                    ->parameters(['proveedores' => 'proveedor']);
            });
        });
    });
});
