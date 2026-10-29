<?php

namespace Tests\Unit\Compartido\Semilla;

use App\Compartido\Infraestructura\Semilla\GuardaDatosPrueba;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 011: guarda de la semilla y del vaciado (docs/datos-prueba.md sección 12): nunca en producción ni con una base que no
 * es local. Sin base de datos.
 */
class GuardaDatosPruebaTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function conexionesLocales(): array
    {
        return [
            'localhost' => [['driver' => 'pgsql', 'host' => 'localhost']],
            '127.0.0.1' => [['driver' => 'pgsql', 'host' => '127.0.0.1']],
            '::1' => [['driver' => 'pgsql', 'host' => '::1']],
            'contenedor de infra' => [['driver' => 'pgsql', 'host' => 'postgres']],
            'mayúsculas y espacios' => [['driver' => 'pgsql', 'host' => ' LocalHost ']],
            'lectura y escritura locales' => [['driver' => 'pgsql', 'host' => ['localhost', '127.0.0.1']]],
            'DB_URL local' => [['driver' => 'pgsql', 'host' => 'db.remota.com', 'url' => 'pgsql://u:c@127.0.0.1:5433/finanzas_db']],
            'DB_URL con IPv6' => [['driver' => 'pgsql', 'url' => 'pgsql://u:c@[::1]:5433/finanzas_db']],
            'sqlite de las pruebas' => [['driver' => 'sqlite', 'database' => ':memory:']],
        ];
    }

    /**
     * @param  array<string, mixed>  $conexion
     */
    #[DataProvider('conexionesLocales')]
    public function test_permite_desarrollo_con_base_local(array $conexion): void
    {
        $this->assertNull(GuardaDatosPrueba::motivoParaNegarse('local', $conexion));
    }

    public function test_se_niega_en_produccion_aunque_la_base_sea_local(): void
    {
        $motivo = GuardaDatosPrueba::motivoParaNegarse('production', ['driver' => 'pgsql', 'host' => 'localhost']);

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('production', $motivo);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function conexionesRemotas(): array
    {
        return [
            'host remoto' => [['driver' => 'pgsql', 'host' => 'finanzas.abc123.us-east-1.rds.amazonaws.com']],
            'IP de la red' => [['driver' => 'pgsql', 'host' => '10.0.0.5']],
            'una de lectura remota' => [['driver' => 'pgsql', 'host' => ['localhost', '10.0.0.5']]],
            'DB_URL remota aunque host sea local' => [['driver' => 'pgsql', 'host' => 'localhost', 'url' => 'pgsql://u:c@db.remota.com/finanzas']],
            'sin host' => [['driver' => 'pgsql', 'host' => '']],
        ];
    }

    /**
     * @param  array<string, mixed>  $conexion
     */
    #[DataProvider('conexionesRemotas')]
    public function test_se_niega_si_la_base_no_es_local(array $conexion): void
    {
        $motivo = GuardaDatosPrueba::motivoParaNegarse('local', $conexion);

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('no es local', $motivo);
    }
}
