<?php

namespace Tests\Unit\Cobros\Dominio;

use App\Cobros\Dominio\CorteResumen;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fechas del resumen de cobros (009): hoy en La Paz (UTC−4) y hoy + 30 días. Sin base de datos.
 */
class CorteResumenTest extends TestCase
{
    #[DataProvider('instantes')]
    public function test_toma_la_fecha_de_hoy_en_la_paz_y_suma_30_dias(string $ahora, string $hoy, string $limite): void
    {
        $corte = CorteResumen::al(new DateTimeImmutable($ahora));

        $this->assertSame($hoy, $corte->hoy->format('Y-m-d'));
        $this->assertSame('00:00:00', $corte->hoy->format('H:i:s'));
        $this->assertSame('America/La_Paz', $corte->hoy->getTimezone()->getName());
        $this->assertSame($limite, $corte->limitePorVencer->format('Y-m-d'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function instantes(): array
    {
        return [
            'mediodía en La Paz' => ['2026-10-29T16:00:00Z', '2026-10-29', '2026-11-28'],
            // 02:00 UTC del 30 todavía es el 29 a las 22:00 en La Paz.
            'noche en La Paz, ya otro día en UTC' => ['2026-10-30T02:00:00Z', '2026-10-29', '2026-11-28'],
            'medianoche en La Paz' => ['2026-10-30T04:00:00Z', '2026-10-30', '2026-11-29'],
            'con otra zona' => ['2026-12-15T23:30:00+09:00', '2026-12-15', '2027-01-14'],
            'fin de enero' => ['2027-01-31T12:00:00-04:00', '2027-01-31', '2027-03-02'],
        ];
    }
}
