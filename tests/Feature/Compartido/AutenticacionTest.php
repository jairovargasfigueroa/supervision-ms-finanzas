<?php

namespace Tests\Feature\Compartido;

use App\Compartido\Infraestructura\Auth\ValidadorTokenKeycloak;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Utilidades\TokensPrueba;

/**
 * Valida el token sin Keycloak: se generan claves RSA de prueba y el JWKS se sirve con Http::fake.
 */
class AutenticacionTest extends TestCase
{
    use TokensPrueba;

    private const RUTA_PROTEGIDA = '/ruta-que-no-existe';

    protected function setUp(): void
    {
        parent::setUp();

        $this->simularKeycloak();
    }

    public function test_sin_token_responde_401_problem_details(): void
    {
        $respuesta = $this->getJson(self::RUTA_PROTEGIDA);

        $respuesta->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson([
                'type' => 'about:blank',
                'title' => 'No autenticado',
                'status' => 401,
                'codigo' => 'NO_AUTENTICADO',
            ])
            ->assertJsonStructure(['detail']);
        Http::assertNothingSent();
    }

    public function test_sin_token_tambien_en_post_a_una_ruta_existente_solo_por_get(): void
    {
        $this->postJson('/salud')->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_token_que_no_es_jwt_responde_401(): void
    {
        $this->conToken('esto-no-es-un-jwt')->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_token_firmado_con_otra_clave_responde_401(): void
    {
        $token = $this->firmarCon($this->datosToken(), 'otra');

        $this->conToken($token)->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_token_con_kid_desconocido_responde_401(): void
    {
        $token = $this->firmarCon($this->datosToken(), 'otra', 'kid-desconocido');

        $this->conToken($token)->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_token_con_otro_emisor_responde_401(): void
    {
        $token = $this->firmar(['iss' => 'http://localhost:8080/realms/otro']);

        $this->conToken($token)->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_token_con_otra_audiencia_responde_401(): void
    {
        $token = $this->firmar(['aud' => ['account', 'otra-api']]);

        $this->conToken($token)->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_token_vencido_responde_401(): void
    {
        $token = $this->firmar(['iat' => time() - 600, 'exp' => time() - 60]);

        $this->conToken($token)
            ->assertUnauthorized()
            ->assertJsonPath('codigo', 'NO_AUTENTICADO')
            ->assertJsonPath('detail', 'El token venció.');
    }

    public function test_token_sin_vencimiento_responde_401(): void
    {
        $datos = $this->datosToken();
        unset($datos['exp']);
        $token = $this->firmarCon($datos);

        $this->conToken($token)->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_token_valido_pasa_y_la_ruta_inexistente_responde_404(): void
    {
        $this->conToken($this->firmar())
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 404);
    }

    public function test_acepta_la_audiencia_como_texto(): void
    {
        $this->conToken($this->firmar(['aud' => self::AUDIENCIA_PRUEBA]))->assertNotFound();
    }

    public function test_guarda_el_jwks_en_cache_entre_peticiones(): void
    {
        $this->conToken($this->firmar())->assertNotFound();
        $this->app->forgetInstance(ValidadorTokenKeycloak::class);
        $this->conToken($this->firmar())->assertNotFound();

        Http::assertSentCount(1);
    }

    public function test_si_keycloak_no_responde_devuelve_503(): void
    {
        $this->keycloakDisponible = false;

        $this->conToken($this->firmar())
            ->assertServiceUnavailable()
            ->assertJsonPath('codigo', 'SERVICIO_NO_DISPONIBLE');
    }

    public function test_devuelve_el_x_request_id_tambien_en_el_401(): void
    {
        $this->getJson(self::RUTA_PROTEGIDA, ['X-Request-Id' => 'req-401'])
            ->assertUnauthorized()
            ->assertHeader('X-Request-Id', 'req-401');
    }

    public function test_firmar_con_roles_pone_los_roles_y_el_azp(): void
    {
        $token = $this->firmarConRoles(['residente', 'cliente'], 'portal');
        $datos = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

        $this->assertSame('portal', $datos['azp']);
        $this->assertSame(['residente', 'cliente'], $datos['realm_access']['roles']);
        $this->conToken($token)->assertNotFound();
    }

    private function conToken(string $token): TestResponse
    {
        return $this->getJson(self::RUTA_PROTEGIDA, ['Authorization' => 'Bearer '.$token]);
    }
}
