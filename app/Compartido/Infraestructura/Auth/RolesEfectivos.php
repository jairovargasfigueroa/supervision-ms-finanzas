<?php

namespace App\Compartido\Infraestructura\Auth;

/**
 * Roles que cuentan según la aplicación desde la que se inició sesión (`azp` del token)
 * (docs/convenciones.md, «Usuario con varios roles»):
 * - `portal`: solo `cliente`;
 * - `erp` o `app-obra`: solo los roles internos;
 * - otro cliente (de servicio: `gateway`, `ia-agentes`, `n8n`): los roles del token tal cual.
 */
final class RolesEfectivos
{
    /** Roles de las personas que trabajan en la constructora (ERP y app de obra). */
    public const INTERNOS = ['admin', 'residente', 'gerente', 'maestro', 'almacenero'];

    /** Clientes desde los que inicia sesión una persona de la constructora. */
    private const CLIENTES_INTERNOS = ['erp', 'app-obra'];

    /**
     * @param  list<string>  $roles  `realm_access.roles` del token
     * @return list<string>
     */
    public static function de(array $roles, ?string $azp): array
    {
        if ($azp === 'portal') {
            return array_values(array_filter($roles, fn (string $rol) => $rol === 'cliente'));
        }

        if (in_array($azp, self::CLIENTES_INTERNOS, true)) {
            return array_values(array_filter($roles, fn (string $rol) => in_array($rol, self::INTERNOS, true)));
        }

        return $roles;
    }
}
