<?php

namespace Tests\Feature\Mensajeria;

use App\Compartido\Infraestructura\Mensajeria\MensajeProcesado;
use App\Compartido\Infraestructura\Mensajeria\OutboxEvento;
use App\Contratos\Infraestructura\Persistencia\ReservaSaldoModelo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;

/**
 * 008, paso 2: `ReservarSaldo {planillaId, obraId, monto}` → `SaldoReservado {planillaId, reservaId}` o
 * `SaldoInsuficiente {planillaId, motivo}`.
 */
class ReservarSaldoTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCanal();
        $this->crearContrato('100000.00');
    }

    public function test_con_saldo_crea_la_reserva_y_responde_saldo_reservado(): void
    {
        $this->recibir($this->reservar(41881.80));

        $reserva = ReservaSaldoModelo::query()->sole();
        $this->assertSame(self::CONTRATO_ID, $reserva->contrato_id);
        $this->assertSame(self::PLANILLA_ID, $reserva->planilla_id);
        $this->assertSame('41881.80', $reserva->monto);
        $this->assertSame('RESERVADA', $reserva->estado);

        $respuesta = $this->unicaRespuesta('SaldoReservado');
        $this->assertSame(['planillaId' => self::PLANILLA_ID, 'reservaId' => $reserva->id], $respuesta['datos']);
    }

    public function test_sin_saldo_responde_saldo_insuficiente_y_no_reserva(): void
    {
        $this->recibir($this->reservar(100000.01));

        $this->assertSame(0, ReservaSaldoModelo::query()->count());
        $respuesta = $this->unicaRespuesta('SaldoInsuficiente');
        $this->assertSame(['planillaId' => self::PLANILLA_ID, 'motivo' => 'Saldo del contrato insuficiente'], $respuesta['datos']);
    }

    public function test_el_mismo_mensaje_dos_veces_crea_una_sola_reserva(): void
    {
        $mensaje = $this->reservar(1000);

        $this->recibir($mensaje);
        $this->recibir($mensaje);

        $this->assertSame(1, ReservaSaldoModelo::query()->count());
        $this->assertSame(1, OutboxEvento::query()->count());
        $this->assertSame(1, MensajeProcesado::query()->count());
    }

    public function test_otro_mensaje_para_la_misma_planilla_responde_con_la_reserva_vigente(): void
    {
        $this->recibir($this->reservar(1000));
        $this->recibir($this->reservar(1000));

        $reserva = ReservaSaldoModelo::query()->sole();
        $respuestas = $this->respuestas('SaldoReservado');
        $this->assertCount(2, $respuestas);
        $this->assertSame($reserva->id, $respuestas[0]['datos']['reservaId']);
        $this->assertSame($reserva->id, $respuestas[1]['datos']['reservaId']);
    }

    public function test_acepta_el_monto_en_texto_y_lo_redondea_a_dos_decimales(): void
    {
        $this->recibir($this->reservar('1500.005'));

        $this->assertSame('1500.01', ReservaSaldoModelo::query()->sole()->monto);
    }

    public function test_datos_incompletos_van_directo_a_fallidos(): void
    {
        $this->recibir($this->comando('ReservarSaldo', ['planillaId' => self::PLANILLA_ID, 'monto' => 1000]));

        $fallidos = $this->canal->publicadosEn('', 'plataforma.fallidos');
        $this->assertCount(1, $fallidos);
        $this->assertSame('ReservarSaldo sin obraId (uuid).', $fallidos[0]['encabezados']['x-error']);
        $this->assertSame(1, $fallidos[0]['encabezados']['x-intentos']);
        $this->assertSame(0, MensajeProcesado::query()->count());
    }

    public function test_un_monto_cero_es_un_mensaje_invalido(): void
    {
        $this->recibir($this->reservar(0));

        $fallidos = $this->canal->publicadosEn('', 'plataforma.fallidos');
        $this->assertCount(1, $fallidos);
        $this->assertSame('ReservarSaldo: monto debe ser mayor a 0.', $fallidos[0]['encabezados']['x-error']);
    }

    public function test_una_obra_sin_contrato_se_reintenta_y_termina_en_fallidos(): void
    {
        $this->recibir($this->reservar(1000, obraId: '0b1a0000-0000-4000-8000-0000000000ff'));

        $this->assertCount(2, $this->canal->publicadosEn('', 'finanzas'), 'Dos reintentos.');
        $fallidos = $this->canal->publicadosEn('', 'plataforma.fallidos');
        $this->assertCount(1, $fallidos);
        $this->assertSame(3, $fallidos[0]['encabezados']['x-intentos']);
        $this->assertSame('ReservarSaldo: la obra no tiene contrato.', $fallidos[0]['encabezados']['x-error']);
        $this->assertSame(0, OutboxEvento::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function reservar(int|float|string $monto, string $obraId = self::OBRA_ID): array
    {
        return $this->comando('ReservarSaldo', ['planillaId' => self::PLANILLA_ID, 'obraId' => $obraId, 'monto' => $monto]);
    }
}
