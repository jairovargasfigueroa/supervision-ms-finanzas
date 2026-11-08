<?php

namespace Tests\Unit\Cobros;

use App\Cobros\Infraestructura\Pdf\FormatoBolivia;
use App\Compartido\Dominio\Dinero;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 201: montos, fechas y periodos de la nota de cobro (docs/convenciones.md). */
class FormatoBoliviaTest extends TestCase
{
    #[DataProvider('montos')]
    public function test_monto_en_bolivianos(string $monto, string $esperado): void
    {
        $this->assertSame($esperado, FormatoBolivia::monto(Dinero::desde($monto)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function montos(): array
    {
        return [
            'con miles' => ['330149.10', 'Bs 330.149,10'],
            'millones' => ['2773800', 'Bs 2.773.800,00'],
            'menor a mil' => ['45.5', 'Bs 45,50'],
            'cero' => ['0', 'Bs 0,00'],
        ];
    }

    public function test_cantidad_sin_ceros_de_mas(): void
    {
        $this->assertSame('40', FormatoBolivia::numero(40));
        $this->assertSame('1.000,5', FormatoBolivia::numero(1000.5));
        $this->assertSame('12,375', FormatoBolivia::numero(12.375));
    }

    public function test_fecha_dd_mm_aaaa(): void
    {
        $this->assertSame('02/10/2026', FormatoBolivia::fecha(new DateTimeImmutable('2026-10-02')));
    }

    public function test_periodo_con_el_mes_en_letras(): void
    {
        $this->assertSame('septiembre 2026', FormatoBolivia::periodo('2026-09'));
        $this->assertSame('enero 2027', FormatoBolivia::periodo('2027-01'));
        $this->assertSame('diciembre 2026', FormatoBolivia::periodo('2026-12'));
    }
}
