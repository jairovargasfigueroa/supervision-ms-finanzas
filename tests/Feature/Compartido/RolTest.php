<?php

namespace Tests\Feature\Compartido;

use App\Compartido\Infraestructura\Auth\MiddlewareToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Utilidades\TokensPrueba;

/**
 * Middleware `rol:...` (001). Las rutas solo existen en esta prueba: todavía no hay endpoints del negocio.
 */
class RolTest extends TestCase
{
    use TokensPrueba;

    private const ADMIN_GERENTE = '/prueba-roles/admin-gerente';

    private const CLIENTE = '/prueba-roles/cliente';

    private const SIN_ROLES = '/prueba-roles/sin-roles';

    private const PUBLICA = '/prueba-roles/publica';

    protected function setUp(): void
    {
        parent::setUp();

        $this->simularKeycloak();

        Route::middleware(['api', 'rol:admin,gerente'])->get(self::ADMIN_GERENTE, fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'rol:cliente'])->get(self::CLIENTE, fn () => response()->json(['ok' => true]));
        Route::middleware('api')->get(self::SIN_ROLES, fn () => response()->json(['ok' => true]));
        // Una ruta pública con rol: sin token no hay usuario, así que responde 401 en vez de dejar pasar.
        Route::middleware(['api', 'rol:admin'])->withoutMiddleware(MiddlewareToken::class)
            ->get(self::PUBLICA, fn () => response()->json(['ok' => true]));
    }

    public function test_sin_token_responde_401_antes_que_el_rol(): void
    {
        $this->getJson(self::ADMIN_GERENTE)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_con_un_token_invalido_responde_401(): void
    {
        $this->conToken(self::ADMIN_GERENTE, $this->firmarCon($this->datosToken(), 'otra'))
            ->assertUnauthorized()
            ->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_residente_en_una_ruta_admin_gerente_responde_403_sin_permiso(): void
    {
        $this->esperar403(self::ADMIN_GERENTE, $this->firmarConRoles(['residente'], 'erp'));
    }

    public function test_admin_en_una_ruta_admin_gerente_responde_200(): void
    {
        $this->conToken(self::ADMIN_GERENTE, $this->firmarConRoles(['admin'], 'erp'))
            ->assertOk()
            ->assertExactJson(['ok' => true]);
    }

    public function test_gerente_en_una_ruta_admin_gerente_responde_200(): void
    {
        $this->conToken(self::ADMIN_GERENTE, $this->firmarConRoles(['gerente'], 'erp'))->assertOk();
    }

    public function test_admin_que_inicio_sesion_en_el_portal_no_cuenta_como_admin(): void
    {
        $this->esperar403(self::ADMIN_GERENTE, $this->firmarConRoles(['admin', 'cliente'], 'portal'));
    }

    public function test_demo1_con_azp_portal_entra_a_una_ruta_de_cliente(): void
    {
        $this->conToken(self::CLIENTE, $this->firmarConRoles(['residente', 'cliente'], 'portal'))->assertOk();
    }

    public function test_demo1_con_azp_erp_no_entra_a_una_ruta_de_cliente(): void
    {
        $this->esperar403(self::CLIENTE, $this->firmarConRoles(['residente', 'cliente'], 'erp'));
    }

    public function test_cliente_con_azp_erp_no_cuenta_como_cliente(): void
    {
        $this->esperar403(self::CLIENTE, $this->firmarConRoles(['cliente'], 'erp'));
    }

    public function test_un_cliente_de_servicio_usa_los_roles_del_token_tal_cual(): void
    {
        $this->conToken(self::CLIENTE, $this->firmarConRoles(['cliente'], 'gateway'))->assertOk();
    }

    public function test_una_ruta_sin_rol_exige_solo_token_valido(): void
    {
        $this->getJson(self::SIN_ROLES)->assertUnauthorized();
        $this->conToken(self::SIN_ROLES, $this->firmarConRoles(['cliente'], 'portal'))->assertOk();
    }

    public function test_el_rol_sin_token_validado_responde_401(): void
    {
        $this->getJson(self::PUBLICA)->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_salud_sigue_sin_token_ni_rol(): void
    {
        $this->getJson('/salud')->assertOk()->assertExactJson(['estado' => 'ok']);
    }

    private function esperar403(string $ruta, string $token): void
    {
        $this->conToken($ruta, $token)
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson([
                'type' => 'about:blank',
                'title' => 'Sin permiso',
                'status' => 403,
                'codigo' => 'SIN_PERMISO',
            ])
            ->assertJsonStructure(['detail']);
    }

    private function conToken(string $ruta, string $token): TestResponse
    {
        return $this->getJson($ruta, ['Authorization' => 'Bearer '.$token]);
    }
}
