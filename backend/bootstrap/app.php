<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sesión por cookie para la SPA (Sanctum).
        $middleware->statefulApi();

        $middleware->alias([
            'activo' => EnsureUserIsActive::class,
            'rol' => EnsureUserHasRole::class,
            'password.cambiado' => EnsurePasswordIsChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // ThrottleRequests fija «Too Many Attempts.» en el código, así que el archivo de idioma no lo
        // traduce. Se conservan las cabeceras de la excepción (Retry-After, X-RateLimit-*).
        $exceptions->map(ThrottleRequestsException::class, fn (ThrottleRequestsException $e) => new ThrottleRequestsException(
            'Demasiadas solicitudes. Espere un minuto e inténtelo de nuevo.',
            $e,
            $e->getHeaders(),
        ));
    })->create();
