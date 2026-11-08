<?php

namespace App\Compartido\Infraestructura\Auth;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * `rol:admin,gerente`: exige que el usuario del token ya validado (MiddlewareToken, que va antes en
 * el grupo `api`) tenga uno de esos roles. Cuentan solo los roles de la aplicación desde la que se
 * inició sesión (RolesEfectivos). Si no tiene ninguno responde `403 SIN_PERMISO` (lo arma
 * ManejadorProblemDetails). Una ruta sin `rol:` exige solo token válido.
 */
final class MiddlewareRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($roles === []) {
            throw new LogicException('El middleware rol necesita al menos un rol (rol:admin,gerente).');
        }

        if (! UsuarioActual::desde($request)->tieneAlgunRol($roles)) {
            throw new AccessDeniedHttpException('Tu rol no permite esta acción.');
        }

        return $next($request);
    }
}
