<?php

namespace App\Compartido\Infraestructura\Auth;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un token de Keycloak válido (`Authorization: Bearer ...`). Sin token o con uno inválido
 * responde `401 NO_AUTENTICADO` (lo arma ManejadorProblemDetails).
 *
 * Deja los datos del token en `$request->attributes->get('token')`.
 */
final class MiddlewareToken
{
    public function __construct(private readonly ValidadorTokenKeycloak $validador) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            throw new AuthenticationException('Falta el token de acceso.');
        }

        $request->attributes->set('token', $this->validador->validar($token));

        return $next($request);
    }
}
