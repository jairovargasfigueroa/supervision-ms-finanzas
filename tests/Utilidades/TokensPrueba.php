<?php

namespace Tests\Utilidades;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Tokens de prueba sin Keycloak: claves RSA generadas en la prueba y el JWKS servido con Http::fake.
 *
 * Uso: llamar a `simularKeycloak()` en `setUp()` y firmar con `firmar()` o `firmarConRoles()`.
 */
trait TokensPrueba
{
    protected const EMISOR_PRUEBA = 'http://localhost:8080/realms/supervision';

    protected const AUDIENCIA_PRUEBA = 'plataforma-api';

    protected const KID_PRUEBA = 'clave-prueba';

    /** Si es false, el JWKS responde 500 (Keycloak caído). */
    protected bool $keycloakDisponible = true;

    /** @var array<string, OpenSSLAsymmetricKey> */
    private static array $privadas = [];

    /** Sirve el JWKS de prueba en la URL del realm; cualquier otra URL responde 404. */
    protected function simularKeycloak(): void
    {
        Http::fake(fn (Request $peticion) => match (true) {
            $peticion->url() !== self::EMISOR_PRUEBA.'/protocol/openid-connect/certs' => Http::response('', 404),
            ! $this->keycloakDisponible => Http::response('', 500),
            default => Http::response($this->jwks()),
        });
    }

    /**
     * Token válido (por defecto `residente` con `azp = erp`) con los cambios indicados.
     *
     * @param  array<string, mixed>  $cambios
     */
    protected function firmar(array $cambios = []): string
    {
        return $this->firmarCon(array_merge($this->datosToken(), $cambios));
    }

    /**
     * Token válido con esos roles (`realm_access.roles`), iniciado desde el cliente `azp`.
     * Ejemplo: `firmarConRoles(['residente', 'cliente'], 'portal')`.
     *
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $cambios
     */
    protected function firmarConRoles(array $roles, string $azp = 'erp', array $cambios = []): string
    {
        return $this->firmar(array_merge(['azp' => $azp, 'realm_access' => ['roles' => $roles]], $cambios));
    }

    /**
     * Firma los datos tal cual, con la clave indicada (`realm` es la del JWKS) y el `kid` indicado.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function firmarCon(array $datos, string $clave = 'realm', string $kid = self::KID_PRUEBA): string
    {
        return JWT::encode($datos, self::privada($clave), 'RS256', $kid);
    }

    /** @return array<string, mixed> */
    protected function datosToken(): array
    {
        return [
            'iss' => self::EMISOR_PRUEBA,
            'aud' => [self::AUDIENCIA_PRUEBA, 'account'],
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
    protected function jwks(): array
    {
        return ['keys' => [
            self::jwk(self::privada('realm'), self::KID_PRUEBA, 'sig', 'RS256'),
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
