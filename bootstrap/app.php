<?php

use App\Compartido\Infraestructura\Auth\MiddlewareToken;
use App\Compartido\Infraestructura\Http\ManejadorProblemDetails;
use App\Compartido\Infraestructura\Http\MiddlewareEncabezadosSeguridad;
use App\Compartido\Infraestructura\Http\MiddlewareRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Rutas en la raíz (GET /salud, GET /contratos...), sin el prefijo /api (docs/contratos.md).
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(MiddlewareRequestId::class);
        $middleware->append(MiddlewareEncabezadosSeguridad::class);
        $middleware->alias(['token' => MiddlewareToken::class]);
        // Toda ruta de la API exige token por defecto; solo /salud lo quita explícitamente (routes/api.php).
        $middleware->api(append: [MiddlewareToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ManejadorProblemDetails::registrar($exceptions);
    })->create();
