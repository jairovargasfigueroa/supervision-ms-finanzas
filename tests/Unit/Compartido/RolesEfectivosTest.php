<?php

namespace Tests\Unit\Compartido;

use App\Compartido\Infraestructura\Auth\RolesEfectivos;
use App\Compartido\Infraestructura\Auth\UsuarioActual;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RolesEfectivosTest extends TestCase
{
    public function test_con_azp_portal_solo_cuenta_cliente(): void
    {
        $this->assertSame(['cliente'], RolesEfectivos::de(['residente', 'cliente'], 'portal'));
    }

    public function test_con_azp_portal_y_sin_cliente_no_queda_ningun_rol(): void
    {
        $this->assertSame([], RolesEfectivos::de(['admin'], 'portal'));
    }

    #[DataProvider('clientesInternos')]
    public function test_con_un_cliente_interno_solo_cuentan_los_roles_internos(string $azp): void
    {
        $this->assertSame(
            ['admin', 'residente', 'gerente', 'maestro', 'almacenero'],
            RolesEfectivos::de(['admin', 'residente', 'cliente', 'gerente', 'maestro', 'almacenero', 'offline_access'], $azp),
        );
    }

    /** @return array<string, array{string}> */
    public static function clientesInternos(): array
    {
        return ['erp' => ['erp'], 'app-obra' => ['app-obra']];
    }

    public function test_demo1_con_azp_erp_solo_es_residente(): void
    {
        $this->assertSame(['residente'], RolesEfectivos::de(['residente', 'cliente'], 'erp'));
    }

    public function test_cliente_con_azp_erp_no_cuenta_como_cliente(): void
    {
        $this->assertSame([], RolesEfectivos::de(['cliente'], 'erp'));
    }

    #[DataProvider('clientesDeServicio')]
    public function test_con_un_cliente_de_servicio_deja_los_roles_tal_cual(string $azp): void
    {
        $this->assertSame(['automatizacion', 'cliente'], RolesEfectivos::de(['automatizacion', 'cliente'], $azp));
    }

    /** @return array<string, array{string}> */
    public static function clientesDeServicio(): array
    {
        return ['gateway' => ['gateway'], 'ia-agentes' => ['ia-agentes'], 'n8n' => ['n8n']];
    }

    public function test_sin_azp_deja_los_roles_tal_cual(): void
    {
        $this->assertSame(['agente_ia'], RolesEfectivos::de(['agente_ia'], null));
    }

    public function test_usuario_actual_lee_el_token_y_aplica_los_roles_efectivos(): void
    {
        $token = json_decode((string) json_encode([
            'sub' => 'u1',
            'azp' => 'portal',
            'realm_access' => ['roles' => ['residente', 'cliente']],
        ]));

        $usuario = UsuarioActual::desdeToken($token);

        $this->assertSame('u1', $usuario->id);
        $this->assertSame(['residente', 'cliente'], $usuario->roles);
        $this->assertSame(['cliente'], $usuario->rolesEfectivos());
        $this->assertTrue($usuario->tieneAlgunRol(['cliente']));
        $this->assertFalse($usuario->tieneAlgunRol(['admin', 'residente']));
    }

    public function test_usuario_actual_sin_realm_access_no_tiene_roles(): void
    {
        $token = json_decode((string) json_encode(['sub' => 'u1', 'azp' => 'erp']));

        $this->assertSame([], UsuarioActual::desdeToken($token)->roles);
    }
}
