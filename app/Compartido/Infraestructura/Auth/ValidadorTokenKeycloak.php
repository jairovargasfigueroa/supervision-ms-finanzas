<?php

namespace App\Compartido\Infraestructura\Auth;

use DomainException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnexpectedValueException;

/**
 * Valida los tokens de Keycloak: firma con el JWKS del realm, vencimiento, emisor y audiencia.
 *
 * El JWKS se pide recién cuando llega el primer token (nunca al arrancar) y se guarda en la caché.
 * Si el token trae un `kid` que no está en la caché (Keycloak rotó sus claves), se vuelve a pedir,
 * como mucho una vez por minuto.
 */
final class ValidadorTokenKeycloak
{
    private const CLAVE_CACHE = 'keycloak.jwks';

    private const CLAVE_RECARGA = 'keycloak.jwks.recarga';

    private const DURACION_CACHE_SEGUNDOS = 3600;

    private const ESPERA_RECARGA_SEGUNDOS = 60;

    private const TIEMPO_MAXIMO_SEGUNDOS = 3;

    private const ALGORITMO = 'RS256';

    /** @var array<string, Key>|null */
    private ?array $claves = null;

    public function __construct(
        private readonly string $emisor,
        private readonly string $audiencia,
    ) {}

    /**
     * Devuelve los datos del token si es válido.
     *
     * @throws AuthenticationException si la firma, el vencimiento, el emisor o la audiencia no son válidos
     * @throws HttpException 503 si no se pudo obtener el JWKS de Keycloak
     */
    public function validar(string $token): stdClass
    {
        if ($this->emisor === '' || $this->audiencia === '') {
            throw new RuntimeException('Faltan KEYCLOAK_ISSUER o KEYCLOAK_AUDIENCIA en la configuración.');
        }

        try {
            $datos = $this->decodificar($token);
        } catch (ExpiredException) {
            throw new AuthenticationException('El token venció.');
        } catch (UnexpectedValueException|InvalidArgumentException|DomainException $e) {
            // Firma, formato, kid desconocido o token aún no vigente. (Las fallas de Keycloak o de la caché siguen de largo.)
            Log::info('Token rechazado', ['motivo' => $e->getMessage()]);
            throw new AuthenticationException('El token no es válido.');
        }

        if (! isset($datos->exp)) {
            throw new AuthenticationException('El token no es válido.');
        }

        if (($datos->iss ?? null) !== $this->emisor) {
            Log::info('Token rechazado', ['motivo' => 'emisor distinto']);
            throw new AuthenticationException('El token no es válido.');
        }

        $audiencias = (array) ($datos->aud ?? []);
        if (! in_array($this->audiencia, $audiencias, true)) {
            Log::info('Token rechazado', ['motivo' => 'audiencia distinta']);
            throw new AuthenticationException('El token no es válido.');
        }

        return $datos;
    }

    private function decodificar(string $token): stdClass
    {
        try {
            return JWT::decode($token, $this->claves());
        } catch (UnexpectedValueException $e) {
            // Puede que Keycloak haya rotado sus claves: se recarga el JWKS una vez y se reintenta.
            if (! str_contains($e->getMessage(), '"kid"') || ! Cache::add(self::CLAVE_RECARGA, true, self::ESPERA_RECARGA_SEGUNDOS)) {
                throw $e;
            }
            Cache::forget(self::CLAVE_CACHE);
            $this->claves = null;

            return JWT::decode($token, $this->claves());
        }
    }

    /** @return array<string, Key> */
    private function claves(): array
    {
        if ($this->claves !== null) {
            return $this->claves;
        }

        /** @var array{keys: list<array<string, mixed>>} $jwks */
        $jwks = Cache::remember(self::CLAVE_CACHE, self::DURACION_CACHE_SEGUNDOS, fn () => $this->descargarJwks());

        // Keycloak publica también una clave de cifrado (use = enc); solo sirven las de firma.
        $deFirma = array_values(array_filter(
            $jwks['keys'],
            fn (array $clave) => ($clave['use'] ?? 'sig') === 'sig' && ($clave['kty'] ?? null) === 'RSA',
        ));

        if ($deFirma === []) {
            Log::error('El JWKS de Keycloak no trae claves de firma RSA');
            throw new HttpException(503, 'No se pudo validar el token: el JWKS no trae claves de firma.');
        }

        return $this->claves = JWK::parseKeySet(['keys' => $deFirma], self::ALGORITMO);
    }

    /** @return array{keys: list<array<string, mixed>>} */
    private function descargarJwks(): array
    {
        $url = rtrim($this->emisor, '/').'/protocol/openid-connect/certs';

        try {
            $jwks = Http::timeout(self::TIEMPO_MAXIMO_SEGUNDOS)->acceptJson()->get($url)->throw()->json();
        } catch (ConnectionException|RequestException $e) {
            Log::error('No se pudo obtener el JWKS de Keycloak', ['url' => $url, 'error' => $e->getMessage()]);
            throw new HttpException(503, 'No se pudo validar el token: Keycloak no responde.');
        }

        if (! is_array($jwks) || ! isset($jwks['keys']) || ! is_array($jwks['keys'])) {
            Log::error('El JWKS de Keycloak no tiene el formato esperado', ['url' => $url]);
            throw new HttpException(503, 'No se pudo validar el token: el JWKS no es válido.');
        }

        return $jwks;
    }
}
