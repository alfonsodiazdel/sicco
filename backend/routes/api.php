<?php

use App\Http\Controllers\Api\V1\AlertaController;
use App\Http\Controllers\Api\V1\AsignacionController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SesionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DesperdicioController;
use App\Http\Controllers\Api\V1\DocumentoController;
use App\Http\Controllers\Api\V1\ElementoEstructuralController;
use App\Http\Controllers\Api\V1\FacturaController;
use App\Http\Controllers\Api\V1\GrupoPartidaController;
use App\Http\Controllers\Api\V1\GuiaController;
use App\Http\Controllers\Api\V1\GuiaSinSectorController;
use App\Http\Controllers\Api\V1\NivelController;
use App\Http\Controllers\Api\V1\PartidaController;
use App\Http\Controllers\Api\V1\ProveedorController;
use App\Http\Controllers\Api\V1\ProyectoController;
use App\Http\Controllers\Api\V1\ReporteCorteMesController;
use App\Http\Controllers\Api\V1\ReporteEatController;
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
            // Su mantenedor tiene los mismos permisos que los grupos de partidas.
            Route::get('resistencias/todas', [ResistenciaController::class, 'todas'])->middleware('rol:oficina_tecnica,administrador');
            Route::middleware('rol:oficina_tecnica')->group(function () {
                Route::post('resistencias', [ResistenciaController::class, 'store']);
                Route::put('resistencias/{resistencia}', [ResistenciaController::class, 'update'])->whereNumber('resistencia');
                Route::delete('resistencias/{resistencia}', [ResistenciaController::class, 'destroy'])->whereNumber('resistencia');
            });

            // Catálogo global de grupos de partidas: lo mantiene Oficina técnica.
            Route::get('grupos-partida', [GrupoPartidaController::class, 'index'])->middleware('rol:oficina_tecnica,administrador');
            Route::middleware('rol:oficina_tecnica')->group(function () {
                Route::post('grupos-partida', [GrupoPartidaController::class, 'store']);
                Route::put('grupos-partida/{grupo}', [GrupoPartidaController::class, 'update'])->whereNumber('grupo');
                Route::delete('grupos-partida/{grupo}', [GrupoPartidaController::class, 'destroy'])->whereNumber('grupo');
            });

            // Elementos estructurales de las partidas: los activos alimentan los combos de cualquier rol;
            // el listado completo (con inactivos) es del mantenedor, con los mismos permisos que los grupos.
            Route::get('elementos-estructurales', [ElementoEstructuralController::class, 'index']);
            Route::get('elementos-estructurales/todos', [ElementoEstructuralController::class, 'todos'])->middleware('rol:oficina_tecnica,administrador');
            Route::middleware('rol:oficina_tecnica')->group(function () {
                Route::post('elementos-estructurales', [ElementoEstructuralController::class, 'store']);
                Route::put('elementos-estructurales/{elemento}', [ElementoEstructuralController::class, 'update'])->whereNumber('elemento');
                Route::delete('elementos-estructurales/{elemento}', [ElementoEstructuralController::class, 'destroy'])->whereNumber('elemento');
            });

            // CU04 Presupuesto: lo consultan también Producción (para repartirlo en sectores, CU05) y
            // Gerencia; solo de los proyectos asignados.
            Route::get('proyectos/{proyecto}/partidas', [PartidaController::class, 'index'])
                ->whereNumber('proyecto')
                ->middleware('rol:oficina_tecnica,ingeniero_produccion,gerencia,administrador');
            Route::middleware('rol:oficina_tecnica')->group(function () {
                Route::post('proyectos/{proyecto}/partidas', [PartidaController::class, 'store'])->whereNumber('proyecto');
                Route::put('partidas/{partida}', [PartidaController::class, 'update'])->whereNumber('partida');
                Route::delete('partidas/{partida}', [PartidaController::class, 'destroy'])->whereNumber('partida');
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
                Route::delete('niveles/{nivel}', [NivelController::class, 'destroy'])->whereNumber('nivel');
                Route::post('niveles/{nivel}/sectores', [SectorController::class, 'store'])->whereNumber('nivel');
                Route::put('sectores/{sector}', [SectorController::class, 'update'])->whereNumber('sector');
                Route::delete('sectores/{sector}', [SectorController::class, 'destroy'])->whereNumber('sector');
                Route::post('sectores/{sector}/culminar', [SectorController::class, 'culminar'])->whereNumber('sector');
                Route::post('sectores/{sector}/reabrir', [SectorController::class, 'reabrir'])->whereNumber('sector');
            });

            // CU06–CU08 Registrar guía de remisión: el almacenero, desde el celular, en sus proyectos.
            Route::middleware('rol:almacenero')->group(function () {
                // CU07: cada lectura cuesta en la API de IA.
                Route::post('guias/lecturas', [GuiaController::class, 'lectura'])->middleware('throttle:ocr');
                Route::post('guias', [GuiaController::class, 'store']);
                // Una guía subida al proyecto equivocado se elimina para registrarla en el correcto,
                // mientras nadie la haya asignado ni facturado.
                Route::delete('guias/{guia}', [GuiaController::class, 'destroy'])->whereNumber('guia');
            });

            // Foto de la guía: la ve todo rol que consulta guías, solo en sus proyectos.
            Route::get('guias/{guia}/foto', [GuiaController::class, 'foto'])
                ->whereNumber('guia')
                ->middleware('rol:administrador,almacenero,ingeniero_produccion,oficina_tecnica,gerencia');

            // CU10 Guías sin sector: las consultan Producción, Oficina técnica y el almacenero (para
            // revisar lo que registró) en sus proyectos, y CU09 la asignación que se abre desde
            // ellas, que solo guarda Producción.
            Route::middleware('rol:ingeniero_produccion,oficina_tecnica,almacenero,administrador')->group(function () {
                Route::get('proyectos/{proyecto}/guias/sin-sector', [GuiaSinSectorController::class, 'index'])->whereNumber('proyecto');
                Route::get('proyectos/{proyecto}/guias/sin-sector/exportar', [GuiaSinSectorController::class, 'exportar'])->whereNumber('proyecto');
            });
            Route::get('guias/{guia}/asignaciones', [AsignacionController::class, 'show'])
                ->whereNumber('guia')
                ->middleware('rol:ingeniero_produccion,oficina_tecnica,administrador');
            Route::put('guias/{guia}/asignaciones', [AsignacionController::class, 'update'])
                ->whereNumber('guia')
                ->middleware('rol:ingeniero_produccion');

            // CU11 Registrar factura de concreto con CU12 Vincular guías: las registra Oficina técnica
            // en sus proyectos; el administrador las consulta.
            Route::middleware('rol:oficina_tecnica,administrador')->group(function () {
                Route::get('proyectos/{proyecto}/facturas', [FacturaController::class, 'index'])->whereNumber('proyecto');
                Route::get('facturas/{factura}', [FacturaController::class, 'show'])->whereNumber('factura');
                Route::get('facturas/{factura}/archivo', [FacturaController::class, 'archivo'])->whereNumber('factura');
            });
            Route::middleware('rol:oficina_tecnica')->group(function () {
                Route::get('proyectos/{proyecto}/guias/por-facturar', [FacturaController::class, 'guiasPorFacturar'])->whereNumber('proyecto');
                // Lectura de la factura con OCR: cada una cuesta en la API de IA.
                Route::post('facturas/lecturas', [FacturaController::class, 'lectura'])->middleware('throttle:ocr');
                Route::post('facturas', [FacturaController::class, 'store']);
                // CU12 después del registro: agregar las guías que quedaron pendientes o quitar las equivocadas.
                Route::put('facturas/{factura}/guias', [FacturaController::class, 'actualizarGuias'])->whereNumber('factura');
                // Una factura registrada por error (p. ej. en otro proyecto) se elimina para registrarla bien;
                // sus guías vuelven a quedar por facturar y queda constancia en facturas_eliminadas.
                Route::delete('facturas/{factura}', [FacturaController::class, 'destroy'])->whereNumber('factura');
            });

            // CU18 Exportar documentos: Oficina técnica y el administrador descargan en ZIP las fotos
            // de las guías o los archivos de las facturas de sus proyectos.
            Route::middleware('rol:oficina_tecnica,administrador')->group(function () {
                Route::get('proyectos/{proyecto}/documentos/guias', [DocumentoController::class, 'guias'])->whereNumber('proyecto');
                Route::get('proyectos/{proyecto}/documentos/facturas', [DocumentoController::class, 'facturas'])->whereNumber('proyecto');
                Route::get('proyectos/{proyecto}/documentos/{tipo}/zip', [DocumentoController::class, 'zip'])
                    ->whereNumber('proyecto')
                    ->whereIn('tipo', ['guias', 'facturas'])
                    ->middleware('throttle:exportaciones');
            });

            // CU13 Control de desperdicio: lo consultan Producción y Gerencia en sus proyectos.
            Route::get('proyectos/{proyecto}/desperdicio', [DesperdicioController::class, 'show'])
                ->whereNumber('proyecto')
                ->middleware('rol:ingeniero_produccion,gerencia,administrador');

            // CU14 Recibir alerta de consumo: la reciben Producción y Gerencia en sus proyectos y la
            // marcan como revisada; el administrador las consulta.
            Route::middleware('rol:ingeniero_produccion,gerencia,administrador')->group(function () {
                Route::get('alertas', [AlertaController::class, 'index']);
                Route::get('alertas/resumen', [AlertaController::class, 'resumen']);
            });
            Route::patch('alertas/{alerta}/revisar', [AlertaController::class, 'revisar'])
                ->whereNumber('alerta')
                ->middleware('rol:ingeniero_produccion,gerencia');

            // CU15 Reporte EAT: lo generan Oficina técnica y Gerencia en sus proyectos; el administrador
            // lo consulta. Cada consulta con datos escribe en reportes_generados.
            Route::middleware(['rol:oficina_tecnica,gerencia,administrador', 'throttle:reportes'])->group(function () {
                Route::get('proyectos/{proyecto}/reportes/eat', [ReporteEatController::class, 'show'])->whereNumber('proyecto');
                Route::get('proyectos/{proyecto}/reportes/eat/excel', [ReporteEatController::class, 'excel'])->whereNumber('proyecto');
                Route::get('proyectos/{proyecto}/reportes/eat/pdf', [ReporteEatController::class, 'pdf'])->whereNumber('proyecto');
                // CU16 Reporte de corte a fin de mes, con los mismos roles y el mismo registro.
                Route::get('proyectos/{proyecto}/reportes/corte-mes', [ReporteCorteMesController::class, 'show'])->whereNumber('proyecto');
                Route::get('proyectos/{proyecto}/reportes/corte-mes/excel', [ReporteCorteMesController::class, 'excel'])->whereNumber('proyecto');
                Route::get('proyectos/{proyecto}/reportes/corte-mes/pdf', [ReporteCorteMesController::class, 'pdf'])->whereNumber('proyecto');
            });

            // CU17 Dashboard gerencial: Gerencia ve sus proyectos activos; el administrador, todos.
            Route::get('dashboard', [DashboardController::class, 'show'])->middleware('rol:gerencia,administrador');

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
