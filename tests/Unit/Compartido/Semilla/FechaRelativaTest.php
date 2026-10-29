<?php

namespace Tests\Unit\Compartido\Semilla;

use App\Compartido\Infraestructura\Semilla\FechaRelativa;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 011: resolvedor de fechas relativas de la semilla (docs/datos-prueba.md sección 1 y tabla del diseño, con
 * D = 2026-10-28). Sin base de datos.
 */
class FechaRelativaTest extends TestCase
{
    private const D = '2026-10-28';

    /**
     * @return array<string, array{string, string}>
     */
    public static function tablaDelDiseno(): array
    {
        return [
            'D-120' => ['D-120', '2026-06-30'],
            'D+18' => ['D+18', '2026-11-15'],
            'M-3' => ['M-3', '2026-07'],
            'M-1/09 10:00' => ['M-1/09 10:00', '2026-09-09T14:00:00Z'],
            'D-2 11:00' => ['D-2 11:00', '2026-10-26T15:00:00Z'],
        ];
    }

    #[DataProvider('tablaDelDiseno')]
    public function test_resuelve_la_tabla_del_diseno(string $expresion, string $esperado): void
    {
        $this->assertSame($esperado, FechaRelativa::resolver($expresion, $this->d()));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function otrasExpresiones(): array
    {
        return [
            'D sola' => ['D', '2026-10-28'],
            'D-0' => ['D-0', '2026-10-28'],
            'D-130 (firma del contrato 1)' => ['D-130', '2026-06-20'],
            'D+10 (cruza el mes)' => ['D+10', '2026-11-07'],
            'M0' => ['M0', '2026-10'],
            'M+3 (cruza el año)' => ['M+3', '2027-01'],
            'M-1/02 (día mínimo)' => ['M-1/02 10:00', '2026-09-02T14:00:00Z'],
            'M-2/27 (día máximo)' => ['M-2/27 10:00', '2026-08-27T14:00:00Z'],
            'hora que cruza la medianoche en UTC' => ['D-1 22:30', '2026-10-28T02:30:00Z'],
        ];
    }

    #[DataProvider('otrasExpresiones')]
    public function test_resuelve_otras_expresiones(string $expresion, string $esperado): void
    {
        $this->assertSame($esperado, FechaRelativa::resolver($expresion, $this->d()));
    }

    public function test_el_periodo_cruza_el_anio_hacia_atras(): void
    {
        $d = new DateTimeImmutable('2027-01-20');

        $this->assertSame('2026-10', FechaRelativa::resolver('M-3', $d));
        $this->assertSame('2026-12-11', FechaRelativa::resolver('D-40', $d));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malFormadas(): array
    {
        return [
            'vacía' => [''],
            'con espacios' => ['D - 120'],
            'signo unicode' => ['D − 120'],
            'minúscula' => ['d-120'],
            'sin número' => ['D-'],
            'M-0' => ['M-0'],
            'periodo sin día' => ['M-1/ 10:00'],
            'día del periodo sin hora' => ['M-1/09'],
            'día 1' => ['M-1/01 10:00'],
            'día 28' => ['M-1/28 10:00'],
            'día 31' => ['M-1/31 10:00'],
            'hora 24' => ['D-2 24:00'],
            'minuto 60' => ['D-2 10:60'],
            'fecha absoluta' => ['2026-10-28'],
        ];
    }

    #[DataProvider('malFormadas')]
    public function test_rechaza_lo_mal_formado(string $expresion): void
    {
        $this->expectException(InvalidArgumentException::class);

        FechaRelativa::resolver($expresion, $this->d());
    }

    public function test_fecha_devuelve_la_fecha_a_las_00_de_la_paz(): void
    {
        $fecha = FechaRelativa::fecha('D-40', $this->d());

        $this->assertSame('2026-09-18 00:00:00 America/La_Paz', $fecha->format('Y-m-d H:i:s e'));
    }

    public function test_fecha_rechaza_lo_que_no_es_una_fecha(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FechaRelativa::fecha('M-1/09 10:00', $this->d());
    }

    public function test_d_sale_de_semilla_fecha(): void
    {
        $d = FechaRelativa::fechaDeCarga('2026-10-28', new DateTimeImmutable('2030-01-01T12:00:00Z'));

        $this->assertSame('2026-10-28 America/La_Paz', $d->format('Y-m-d e'));
    }

    public function test_sin_semilla_fecha_d_es_hoy_en_la_paz(): void
    {
        // 02:00 UTC del 29: en La Paz todavía es el 28 (22:00).
        $ahora = new DateTimeImmutable('2026-10-29T02:00:00Z');

        $this->assertSame('2026-10-28', FechaRelativa::fechaDeCarga(null, $ahora)->format('Y-m-d'));
        $this->assertSame('2026-10-28', FechaRelativa::fechaDeCarga('', $ahora)->format('Y-m-d'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function semillaFechaInvalida(): array
    {
        return [
            'otro formato' => ['28/10/2026'],
            'mes 13' => ['2026-13-01'],
            '30 de febrero' => ['2026-02-30'],
            'con hora' => ['2026-10-28 10:00'],
        ];
    }

    #[DataProvider('semillaFechaInvalida')]
    public function test_rechaza_semilla_fecha_invalida(string $texto): void
    {
        $this->expectException(InvalidArgumentException::class);

        FechaRelativa::fechaDeCarga($texto, new DateTimeImmutable);
    }

    private function d(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::D);
    }
}
