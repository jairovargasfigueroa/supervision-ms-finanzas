<?php

namespace App\Compartido\Infraestructura\Semilla;

use RuntimeException;

/**
 * Guarda de los datos de prueba (011, docs/datos-prueba.md sección 12): la semilla y el vaciado se niegan a correr con
 * `APP_ENV=production` o si la base no está en `localhost`, `127.0.0.1`, `::1` o en el contenedor `postgres` del
 * Docker Compose de infra. (`SEMILLA_PERMITIDA=si` la comprueba el script de infra.)
 */
final class GuardaDatosPrueba
{
    /** @var list<string> */
    public const HOSTS_LOCALES = ['localhost', '127.0.0.1', '::1', 'postgres'];

    /** Comprueba la configuración de la aplicación; si no se puede, lanza la excepción con el motivo. */
    public static function comprobar(): void
    {
        $nombre = config('database.default');
        $conexion = config('database.connections.'.(is_string($nombre) ? $nombre : ''));
        $entorno = config('app.env');

        $motivo = self::motivoParaNegarse(
            is_string($entorno) ? $entorno : '',
            is_array($conexion) ? $conexion : [],
        );
        if ($motivo !== null) {
            throw new RuntimeException('Datos de prueba: no se ejecuta. '.$motivo);
        }
    }

    /**
     * Por qué no se puede correr con este entorno y esta conexión, o `null` si se puede.
     *
     * @param  array<mixed>  $conexion  la de `config/database.php` (`driver`, `host`, `url`)
     */
    public static function motivoParaNegarse(string $entorno, array $conexion): ?string
    {
        if (strtolower(trim($entorno)) === 'production') {
            return 'Los datos de prueba solo se cargan en desarrollo y APP_ENV es production.';
        }

        // SQLite (pruebas) es un archivo o memoria del propio proceso: siempre local.
        if (($conexion['driver'] ?? null) === 'sqlite') {
            return null;
        }

        foreach (self::hosts($conexion) as $host) {
            if (! in_array($host, self::HOSTS_LOCALES, true)) {
                return "La base está en «{$host}», que no es local (se acepta ".implode(', ', self::HOSTS_LOCALES).').';
            }
        }

        return null;
    }

    /**
     * Hosts a los que se conecta: el de `url` (DB_URL) si viene, porque manda sobre `host`; si no, `host` (texto o
     * lista de lectura y escritura).
     *
     * @param  array<mixed>  $conexion
     * @return list<string>
     */
    private static function hosts(array $conexion): array
    {
        $url = $conexion['url'] ?? null;
        if (is_string($url) && trim($url) !== '') {
            $host = parse_url($url, PHP_URL_HOST);

            return [self::normalizar(is_string($host) ? $host : '')];
        }

        $hosts = $conexion['host'] ?? '';
        $hosts = is_array($hosts) ? $hosts : [$hosts];

        return array_values(array_map(
            fn (mixed $host): string => self::normalizar(is_string($host) ? $host : ''),
            $hosts,
        ));
    }

    private static function normalizar(string $host): string
    {
        return strtolower(trim(trim($host), '[]'));
    }
}
