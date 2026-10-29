<?php

namespace Tests\Unit\Contratos\Dominio;

use App\Compartido\Dominio\Dinero;
use App\Contratos\Dominio\EstadoReserva;
use App\Contratos\Dominio\ReservaSaldo;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Reserva de saldo de la saga (008): `RESERVADA` → `CONSUMIDA` al emitir el cobro, o → `LIBERADA` al compensar.
 * Sin base de datos.
 */
class ReservaSaldoTest extends TestCase
{
    public function test_una_reserva_nueva_esta_vigente(): void
    {
        $reserva = $this->nueva();

        $this->assertSame(EstadoReserva::Reservada, $reserva->estado());
        $this->assertTrue($reserva->estaVigente());
        $this->assertSame('41881.80', $reserva->monto->aDecimal());
    }

    public function test_no_se_reserva_un_monto_cero_o_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReservaSaldo::reservar('r-1', 'c-1', 'p-1', Dinero::desde(0));
    }

    public function test_al_consumirse_deja_de_estar_vigente(): void
    {
        $reserva = $this->nueva();

        $reserva->consumir();

        $this->assertSame(EstadoReserva::Consumida, $reserva->estado());
        $this->assertFalse($reserva->estaVigente());
    }

    public function test_una_reserva_liberada_no_se_puede_consumir(): void
    {
        $reserva = $this->nueva();
        $reserva->liberar();

        $this->expectException(LogicException::class);
        $reserva->consumir();
    }

    public function test_liberar_es_idempotente(): void
    {
        $reserva = $this->nueva();

        $this->assertTrue($reserva->liberar());
        $this->assertFalse($reserva->liberar(), 'La segunda vez no cambia nada.');
        $this->assertSame(EstadoReserva::Liberada, $reserva->estado());
    }

    public function test_una_reserva_consumida_no_se_libera(): void
    {
        $reserva = ReservaSaldo::reconstruir('r-1', 'c-1', 'p-1', Dinero::desde(100), EstadoReserva::Consumida);

        $this->assertFalse($reserva->liberar());
        $this->assertSame(EstadoReserva::Consumida, $reserva->estado());
    }

    private function nueva(): ReservaSaldo
    {
        return ReservaSaldo::reservar('r-1', 'c-1', 'p-1', Dinero::desde('41881.80'));
    }
}
