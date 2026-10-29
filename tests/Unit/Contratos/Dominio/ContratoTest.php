<?php

namespace Tests\Unit\Contratos\Dominio;

use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\ReglaNegocioException;
use App\Contratos\Dominio\Contrato;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validaciones del contrato (docs/reglas-negocio.md y spec 002). Sin base de datos.
 */
class ContratoTest extends TestCase
{
    public function test_un_contrato_valido_empieza_con_el_saldo_igual_al_monto(): void
    {
        $contrato = $this->registrar(monto: 1250000.00, plazo: 300, anticipo: 10, retencion: 7);

        $this->assertSame('obra-1', $contrato->obraId);
        $this->assertSame('1250000.00', $contrato->monto->aDecimal());
        $this->assertSame('1250000.00', $contrato->saldoDisponible()->aDecimal());
        $this->assertSame(300, $contrato->plazoDias);
        $this->assertSame(10.0, $contrato->anticipoPct);
        $this->assertSame(7.0, $contrato->retencionPct);
        $this->assertSame('2026-10-20', $contrato->firmadoEn->format('Y-m-d'));
    }

    public function test_los_porcentajes_en_los_limites_son_validos(): void
    {
        $this->registrar(anticipo: 0, retencion: 0);
        $this->registrar(anticipo: 99.99, retencion: 0);
        $this->registrar(anticipo: 33.33, retencion: 66.66);
        $this->addToAssertionCount(3);
    }

    /**
     * @return array<string, array{int|float, int, int|float, int|float, string}>
     */
    public static function invalidos(): array
    {
        return [
            'monto cero' => [0, 300, 10, 7, Contrato::MOTIVO_MONTO],
            'monto negativo' => [-5, 300, 10, 7, Contrato::MOTIVO_MONTO],
            'monto que redondea a cero' => [0.004, 300, 10, 7, Contrato::MOTIVO_MONTO],
            'plazo cero' => [1000, 0, 10, 7, Contrato::MOTIVO_PLAZO],
            'anticipo negativo' => [1000, 300, -1, 7, Contrato::MOTIVO_PORCENTAJES],
            'retención mayor a 100' => [1000, 300, 0, 100.01, Contrato::MOTIVO_PORCENTAJES.'; '.Contrato::MOTIVO_SUMA_PORCENTAJES],
            'suma exactamente 100' => [1000, 300, 60, 40, Contrato::MOTIVO_SUMA_PORCENTAJES],
            'suma mayor a 100' => [1000, 300, 70, 40, Contrato::MOTIVO_SUMA_PORCENTAJES],
            'varias reglas' => [0, -3, 10, 95, Contrato::MOTIVO_MONTO.'; '.Contrato::MOTIVO_PLAZO.'; '.Contrato::MOTIVO_SUMA_PORCENTAJES],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_un_contrato_invalido_se_rechaza_con_sus_motivos(float $monto, int $plazo, float $anticipo, float $retencion, string $motivo): void
    {
        try {
            $this->registrar($monto, $plazo, $anticipo, $retencion);
            $this->fail('Debía rechazarse.');
        } catch (ReglaNegocioException $e) {
            $this->assertSame(Contrato::CODIGO_INVALIDO, $e->codigo);
            $this->assertSame($motivo, $e->getMessage());
        }
    }

    public function test_los_textos_de_los_motivos_son_los_de_la_spec(): void
    {
        $this->assertSame('El monto del contrato debe ser mayor a 0', Contrato::MOTIVO_MONTO);
        $this->assertSame('El plazo del contrato debe ser mayor a 0 días', Contrato::MOTIVO_PLAZO);
        $this->assertSame('El anticipo y la retención deben estar entre 0 % y 100 %', Contrato::MOTIVO_PORCENTAJES);
        $this->assertSame('El anticipo más la retención deben sumar menos de 100 %', Contrato::MOTIVO_SUMA_PORCENTAJES);
    }

    public function test_dinero_redondea_a_dos_decimales_mitad_hacia_arriba(): void
    {
        $this->assertSame('10.13', Dinero::desde(10.125)->aDecimal());
        $this->assertSame('10.12', Dinero::desde('10.124')->aDecimal());
        $this->assertSame('1250000.00', Dinero::desde(1250000)->aDecimal());
        $this->assertSame('-0.50', Dinero::desde(-0.5)->aDecimal());
        // 1.005 no es exacto en binario: se redondea sobre su texto decimal.
        $this->assertSame('1.01', Dinero::desde(1.005)->aDecimal());
        $this->assertSame('1250000.01', Dinero::desde('1250000.005')->aDecimal());
        $this->assertSame('0.50', Dinero::desde('.5')->aDecimal());
    }

    private function registrar(float $monto = 1000, int $plazo = 300, float $anticipo = 10, float $retencion = 7): Contrato
    {
        return Contrato::registrar('contrato-1', 'obra-1', Dinero::desde($monto), $plazo, $anticipo, $retencion, new DateTimeImmutable('2026-10-20'));
    }
}
