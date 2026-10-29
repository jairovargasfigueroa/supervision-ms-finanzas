<?php

namespace Tests\Unit\Contratos\Dominio;

use App\Compartido\Dominio\Dinero;
use App\Contratos\Dominio\SaldoContrato;
use PHPUnit\Framework\TestCase;

/**
 * Saldo disponible = monto − cobros emitidos − reservas vigentes (docs/reglas-negocio.md, «Contrato»). Sin base de datos.
 */
class SaldoContratoTest extends TestCase
{
    public function test_sin_cobros_ni_reservas_el_saldo_es_el_monto(): void
    {
        $saldo = new SaldoContrato(Dinero::desde('1250000.00'), Dinero::desde(0), Dinero::desde(0));

        $this->assertSame('1250000.00', $saldo->disponible()->aDecimal());
    }

    public function test_descuenta_lo_cobrado_y_lo_reservado_sin_errores_de_redondeo(): void
    {
        $saldo = new SaldoContrato(Dinero::desde('1250000.00'), Dinero::desde('41881.80'), Dinero::desde('0.30'));

        $this->assertSame('1208117.90', $saldo->disponible()->aDecimal());
        $this->assertSame(1208117.9, $saldo->disponible()->aNumero());
    }

    public function test_alcanza_con_saldo_justo_y_no_alcanza_por_un_centavo(): void
    {
        $saldo = new SaldoContrato(Dinero::desde('100000.00'), Dinero::desde('30000.00'), Dinero::desde('20000.00'));

        $this->assertTrue($saldo->alcanzaPara(Dinero::desde('49999.99')));
        $this->assertTrue($saldo->alcanzaPara(Dinero::desde('50000.00')), 'Saldo justo.');
        $this->assertFalse($saldo->alcanzaPara(Dinero::desde('50000.01')));
    }

    public function test_dinero_compara_en_centavos(): void
    {
        $this->assertTrue(Dinero::desde('0.10')->sumar(Dinero::desde('0.20'))->esIgualA(Dinero::desde('0.30')));
        $this->assertTrue(Dinero::desde('0.29')->esMenorQue(Dinero::desde('0.30')));
        $this->assertFalse(Dinero::desde('0.30')->esMenorQue(Dinero::desde('0.30')));
    }

    public function test_dinero_suma_y_resta_en_centavos(): void
    {
        $this->assertSame('0.30', Dinero::desde('0.10')->sumar(Dinero::desde('0.20'))->aDecimal());
        $this->assertSame('-0.10', Dinero::desde('0.20')->restar(Dinero::desde('0.30'))->aDecimal());
    }
}
