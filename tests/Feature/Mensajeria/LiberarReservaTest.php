<?php

namespace Tests\Feature\Mensajeria;

use App\Compartido\Infraestructura\Mensajeria\OutboxEvento;
use App\Contratos\Infraestructura\Persistencia\ReservaSaldoModelo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;

/**
 * 008, compensación del paso 2: `LiberarReserva {planillaId, reservaId}`. Idempotente; publica `ReservaLiberada` solo
 * cuando la reserva estaba vigente.
 */
class LiberarReservaTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCanal();
        $this->crearContrato('100000.00');
    }

    public function test_libera_una_reserva_vigente_y_publica_reserva_liberada(): void
    {
        $reservaId = $this->crearReserva('1000.00');

        $this->recibir($this->liberar($reservaId));

        $this->assertSame('LIBERADA', ReservaSaldoModelo::query()->findOrFail($reservaId)->estado);
        $respuesta = $this->unicaRespuesta('ReservaLiberada');
        $this->assertSame(['planillaId' => self::PLANILLA_ID, 'reservaId' => $reservaId], $respuesta['datos']);
    }

    public function test_liberar_dos_veces_no_cambia_nada_la_segunda(): void
    {
        $reservaId = $this->crearReserva('1000.00');

        $this->recibir($this->liberar($reservaId));
        $this->recibir($this->liberar($reservaId));

        $this->assertSame('LIBERADA', ReservaSaldoModelo::query()->findOrFail($reservaId)->estado);
        $this->assertSame(1, OutboxEvento::query()->count());
        $this->assertSame([], $this->canal->publicados, 'Ningún reintento ni fallido.');
    }

    public function test_una_reserva_consumida_no_se_libera(): void
    {
        $reservaId = $this->crearReserva('1000.00', 'CONSUMIDA');

        $this->recibir($this->liberar($reservaId));

        $this->assertSame('CONSUMIDA', ReservaSaldoModelo::query()->findOrFail($reservaId)->estado);
        $this->assertSame(0, OutboxEvento::query()->count());
    }

    public function test_una_reserva_que_no_existe_no_hace_nada(): void
    {
        $this->recibir($this->liberar('7e5e0000-0000-4000-8000-0000000000ff'));

        $this->assertSame(0, OutboxEvento::query()->count());
        $this->assertSame([], $this->canal->publicados);
    }

    public function test_una_reserva_de_otra_planilla_no_se_libera(): void
    {
        $reservaId = $this->crearReserva('1000.00', planillaId: '9a1a0000-0000-4000-8000-000000000002');

        $this->recibir($this->liberar($reservaId));

        $this->assertSame('RESERVADA', ReservaSaldoModelo::query()->findOrFail($reservaId)->estado);
        $this->assertSame(0, OutboxEvento::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function liberar(string $reservaId): array
    {
        return $this->comando('LiberarReserva', ['planillaId' => self::PLANILLA_ID, 'reservaId' => $reservaId]);
    }
}
