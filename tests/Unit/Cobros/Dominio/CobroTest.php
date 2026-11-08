<?php

namespace Tests\Unit\Cobros\Dominio;

use App\Cobros\Dominio\Cobro;
use App\Cobros\Dominio\EstadoCobro;
use App\Cobros\Dominio\NumeroCobro;
use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\FechaNegocio;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cobro de la 008: número `COB-<año>-<NNNN>`, `PENDIENTE`, vence a los 30 días calendario desde la emisión, con la fecha
 * de La Paz. Sin base de datos.
 */
class CobroTest extends TestCase
{
    public function test_se_emite_pendiente_con_su_numero_y_vence_a_los_30_dias(): void
    {
        $cobro = Cobro::emitir('id-1', 'c-1', 'p-1', 7, Dinero::desde('41881.80'), new DateTimeImmutable('2026-10-29'));

        $this->assertSame('COB-2026-0007', $cobro->numero);
        $this->assertSame(EstadoCobro::Pendiente, $cobro->estado);
        $this->assertSame('2026-10-29', $cobro->emitidoEn->format('Y-m-d'));
        $this->assertSame('2026-11-28', $cobro->venceEn->format('Y-m-d'));
        $this->assertSame('41881.80', $cobro->montoNeto->aDecimal());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function vencimientos(): array
    {
        return [
            'cruza el fin de año' => ['2026-12-15', '2027-01-14'],
            'cruza febrero' => ['2027-02-01', '2027-03-03'],
            'año bisiesto' => ['2028-02-01', '2028-03-02'],
        ];
    }

    #[DataProvider('vencimientos')]
    public function test_los_30_dias_son_calendario(string $emitidoEn, string $venceEn): void
    {
        $cobro = Cobro::emitir('id-1', 'c-1', 'p-1', 1, Dinero::desde(1), new DateTimeImmutable($emitidoEn));

        $this->assertSame($venceEn, $cobro->venceEn->format('Y-m-d'));
    }

    public function test_el_anio_del_numero_es_el_de_la_emision_en_la_paz(): void
    {
        // 03:00 UTC del 1 de enero de 2027 = 23:00 del 31 de diciembre en La Paz.
        $emitidoEn = FechaNegocio::hoy(new DateTimeImmutable('2027-01-01T03:00:00Z'));
        $cobro = Cobro::emitir('id-1', 'c-1', 'p-1', 12, Dinero::desde(1), $emitidoEn);

        $this->assertSame('2026-12-31', $cobro->emitidoEn->format('Y-m-d'));
        $this->assertSame('COB-2026-0012', $cobro->numero);
    }

    public function test_el_numero_tiene_cuatro_digitos_de_correlativo(): void
    {
        $this->assertSame('COB-2026-0001', NumeroCobro::formatear(2026, 1));
        $this->assertSame('COB-2027-0123', NumeroCobro::formatear(2027, 123));
        $this->assertSame('COB-2026-', NumeroCobro::prefijoDelAnio(2026));
    }

    public function test_el_correlativo_empieza_en_1(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NumeroCobro::formatear(2026, 0);
    }
}
