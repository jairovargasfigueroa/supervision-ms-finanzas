<?php

namespace App\Compartido\Infraestructura\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Encabezados de seguridad de las convenciones («Seguridad») en toda respuesta. */
final class MiddlewareEncabezadosSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');
        $respuesta->headers->set('Referrer-Policy', 'no-referrer');

        return $respuesta;
    }
}
