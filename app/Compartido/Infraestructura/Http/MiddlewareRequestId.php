<?php

namespace App\Compartido\Infraestructura\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usa el `X-Request-Id` que llega (lo crea el gateway) o genera un UUID; lo agrega a todos
 * los registros de la petición y lo devuelve en la respuesta.
 */
final class MiddlewareRequestId
{
    public const ENCABEZADO = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->headers->get(self::ENCABEZADO);

        // Solo se acepta un identificador corto y sin caracteres raros, para no ensuciar los registros.
        if (! is_string($id) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $id) !== 1) {
            $id = (string) Str::uuid();
        }

        $request->headers->set(self::ENCABEZADO, $id);
        Log::withContext(['requestId' => $id]);

        $respuesta = $next($request);
        $respuesta->headers->set(self::ENCABEZADO, $id);

        return $respuesta;
    }
}
