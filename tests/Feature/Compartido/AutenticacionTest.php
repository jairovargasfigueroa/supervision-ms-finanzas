<?php

namespace Tests\Feature\Compartido;

use App\Compartido\Infraestructura\Auth\ValidadorTokenKeycloak;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Tests\TestCase;

/**
 * Valida el token sin Keycloak: se generan claves RSA de prueba y el JWKS se sirve con Http::fake.
 */
class AutenticacionTest extends TestCase
{
    private const EMISOR = 'http://localhost:8080/realms/supervision';

    private const AUDIENCIA = 'plataforma-api';

    private const KID = 'clave-prueba';

    private const RUTA_PROTEGIDA = '/ruta-que-no-existe';

    private bool $keycloakDisponible = true;

    /** @var array<string, OpenSSLAsymmetricKey> */
    private static array $privadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn (Request $peticion) => match (true) {
            $peticion->url() !== self::EMISOR.'/protocol/openid-connect/certs' => Http::response('', 404),
            ! $this->keycloakDisponible => Http::response('', 500),
            default => Http::response($this->jwks()),
        });
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
        $token = JWT::encode($this->datos(), self::privada('otra'), 'RS256', self::KID);

        $this->conToken($token)->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_token_con_kid_desconocido_responde_401(): void
    {
        $token = JWT::encode($this->datos(), self::privada('otra'), 'RS256', 'kid-desconocido');

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
        $datos = $this->datos();
        unset($datos['exp']);
        $token = JWT::encode($datos, self::privada('realm'), 'RS256', self::KID);

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
        $this->conToken($this->firmar(['aud' => self::AUDIENCIA]))->assertNotFound();
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

    private function conToken(string $token): TestResponse
    {
        return $this->getJson(self::RUTA_PROTEGIDA, ['Authorization' => 'Bearer '.$token]);
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    private function firmar(array $cambios = []): string
    {
        return JWT::encode(array_merge($this->datos(), $cambios), self::privada('realm'), 'RS256', self::KID);
    }

    /** @return array<string, mixed> */
    private function datos(): array
    {
        return [
            'iss' => self::EMISOR,
            'aud' => [self::AUDIENCIA, 'account'],
            'sub' => '11111111-1111-1111-1111-111111111111',
            'azp' => 'erp',
            'iat' => time(),
            'exp' => time() + 300,
            'realm_access' => ['roles' => ['residente']],
        ];
    }

    /**
     * JWKS como el de Keycloak: la clave de firma del realm y una de cifrado que debe ignorarse.
     *
     * @return array{keys: list<array<string, string>>}
     */
    private function jwks(): array
    {
        return ['keys' => [
            self::jwk(self::privada('realm'), self::KID, 'sig', 'RS256'),
            self::jwk(self::privada('cifrado'), 'clave-cifrado', 'enc', 'RSA-OAEP'),
        ]];
    }

    /** @return array<string, string> */
    private static function jwk(OpenSSLAsymmetricKey $privada, string $kid, string $uso, string $alg): array
    {
        $rsa = openssl_pkey_get_details($privada)['rsa'] ?? throw new RuntimeException('Clave RSA inválida');

        return [
            'kid' => $kid,
            'kty' => 'RSA',
            'alg' => $alg,
            'use' => $uso,
            'n' => JWT::urlsafeB64Encode($rsa['n']),
            'e' => JWT::urlsafeB64Encode($rsa['e']),
        ];
    }

    private static function privada(string $nombre): OpenSSLAsymmetricKey
    {
        if (! isset(self::$privadas[$nombre])) {
            // En Windows, OpenSSL de PHP necesita un archivo de configuración: alcanza con uno vacío.
            $config = tempnam(sys_get_temp_dir(), 'openssl');
            $clave = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => $config,
            ]);
            @unlink((string) $config);
            if ($clave === false) {
                throw new RuntimeException('No se pudo generar la clave RSA de prueba: '.openssl_error_string());
            }
            self::$privadas[$nombre] = $clave;
        }

        return self::$privadas[$nombre];
    }
}
