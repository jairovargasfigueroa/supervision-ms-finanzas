<?php

namespace App\Compartido\Infraestructura\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use stdClass;

/**
 * Usuario del token ya validado por MiddlewareToken (`$request->attributes->get('token')`).
 *
 * Toda regla que decida por rol usa `rolesEfectivos()`, nunca `roles` directo.
 */
final readonly class UsuarioActual
{
    /**
     * @param  string  $id  `sub` del token
     * @param  list<string>  $roles  `realm_access.roles` del token
     * @param  string|null  $azp  cliente desde el que se inició sesión
     */
    public function __construct(
        public string $id,
        public array $roles,
        public ?string $azp,
    ) {}

    /** @throws AuthenticationException si la petición no pasó por MiddlewareToken */
    public static function desde(Request $request): self
    {
        $token = $request->attributes->get('token');

        if (! $token instanceof stdClass) {
            throw new AuthenticationException('Falta el token de acceso.');
        }

        return self::desdeToken($token);
    }

    public static function desdeToken(stdClass $token): self
    {
        $accesoRealm = $token->realm_access ?? null;
        $roles = $accesoRealm instanceof stdClass && is_array($accesoRealm->roles ?? null)
            ? array_values(array_filter($accesoRealm->roles, 'is_string'))
            : [];

        return new self(
            id: is_string($token->sub ?? null) ? $token->sub : '',
            roles: $roles,
            azp: is_string($token->azp ?? null) ? $token->azp : null,
        );
    }

    /** @return list<string> */
    public function rolesEfectivos(): array
    {
        return RolesEfectivos::de($this->roles, $this->azp);
    }

    /** @param  list<string>  $permitidos */
    public function tieneAlgunRol(array $permitidos): bool
    {
        return array_intersect($this->rolesEfectivos(), $permitidos) !== [];
    }
}
