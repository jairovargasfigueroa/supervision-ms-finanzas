<?php

namespace Tests\Feature\Mensajeria;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Contratos\Infraestructura\Persistencia\ReservaSaldoModelo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;
use Tests\Utilidades\TokensPrueba;

/**
 * 008 · pruebas de la spec del lado de Finanzas (diseño, «Pruebas»): reserva con saldo justo y sin saldo, reserva
 * repetida, cobro repetido, liberación repetida, número correlativo y la saga completa con Obras simulado.
 */
class SagaFinanzasTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;
    use TokensPrueba;

    private const OTRA_PLANILLA_ID = '9a1a0000-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCanal();
        $this->simularKeycloak();
        $this->crearContrato('100000.00');
        CarbonImmutable::setTestNow('2026-10-29T15:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_reserva_con_saldo_justo_contando_cobros_y_reservas_vigentes(): void
    {
        // Saldo = 100 000 − 30 000 (cobro) − 20 000 (reserva vigente) = 50 000. Las liberadas y consumidas no cuentan.
        $this->crearCobro('COB-2026-0001', '30000.00');
        $this->crearReserva('20000.00', planillaId: self::OTRA_PLANILLA_ID);
        $this->crearReserva('40000.00', 'LIBERADA', planillaId: '9a1a0000-0000-4000-8000-000000000003');
        $this->crearReserva('30000.00', 'CONSUMIDA', planillaId: '9a1a0000-0000-4000-8000-000000000004');

        $this->recibir($this->reservar(50000));

        $this->assertCount(1, $this->respuestas('SaldoReservado'));
        $this->assertSame([], $this->respuestas('SaldoInsuficiente'));
        $this->assertEquals(0, $this->contrato()['saldoDisponible']);
    }

    public function test_sin_saldo_por_un_centavo_responde_saldo_insuficiente(): void
    {
        $this->crearCobro('COB-2026-0001', '30000.00');
        $this->crearReserva('20000.00', planillaId: self::OTRA_PLANILLA_ID);

        $this->recibir($this->reservar(50000.01));

        $this->assertSame('Saldo del contrato insuficiente', $this->unicaRespuesta('SaldoInsuficiente')['datos']['motivo']);
        $this->assertSame(0, ReservaSaldoModelo::query()->where('planilla_id', self::PLANILLA_ID)->count());
    }

    public function test_camino_feliz_reserva_y_emite_el_cobro(): void
    {
        $this->recibir($this->reservar(41881.80));
        $reservaId = $this->respuestas('SaldoReservado')[0]['datos']['reservaId'];
        $this->assertEquals(['saldoDisponible' => 58118.2, 'totalCobrado' => 0, 'totalReservado' => 41881.8], $this->saldo());

        $this->recibir($this->emitir($reservaId, 41881.80));

        $emitido = $this->respuestas('CobroEmitido');
        $this->assertCount(1, $emitido);
        $this->assertSame('COB-2026-0001', $emitido[0]['datos']['numero']);
        $this->assertSame('2026-11-28', $emitido[0]['datos']['venceEn']);
        $this->assertSame(self::SAGA_ID, $emitido[0]['correlacionId']);
        // La reserva consumida ya no cuenta: el saldo lo descuenta el cobro.
        $this->assertEquals(['saldoDisponible' => 58118.2, 'totalCobrado' => 41881.8, 'totalReservado' => 0], $this->saldo());
    }

    public function test_compensacion_libera_la_reserva_y_un_cobro_tardio_falla(): void
    {
        $this->recibir($this->reservar(41881.80));
        $reservaId = $this->respuestas('SaldoReservado')[0]['datos']['reservaId'];

        // Falló el paso 3: Obras libera la reserva (dos veces, la compensación se reintenta desde su outbox).
        $this->recibir($this->liberar($reservaId));
        $this->recibir($this->liberar($reservaId));
        $this->assertCount(1, $this->respuestas('ReservaLiberada'));
        $this->assertEquals(['saldoDisponible' => 100000, 'totalCobrado' => 0, 'totalReservado' => 0], $this->saldo());

        // Un EmitirCobro que llega tarde no emite nada.
        $this->recibir($this->emitir($reservaId, 41881.80));
        $this->assertSame(
            'No se pudo emitir el cobro: la reserva de saldo ya no está vigente',
            $this->respuestas('CobroFallido')[0]['datos']['motivo'],
        );
        $this->assertSame(0, CobroModelo::query()->count());

        // La planilla corregida se vuelve a aprobar: reserva otra vez con una reserva nueva.
        $this->recibir($this->reservar(41881.80));
        $nueva = $this->respuestas('SaldoReservado')[1]['datos']['reservaId'];
        $this->assertNotSame($reservaId, $nueva);
        $this->assertSame('RESERVADA', ReservaSaldoModelo::query()->findOrFail($nueva)->estado);
    }

    public function test_reserva_y_cobro_repetidos_no_duplican_nada(): void
    {
        $this->recibir($this->reservar(1000));
        $this->recibir($this->reservar(1000));
        $reservas = array_map(fn (array $r) => $r['datos']['reservaId'], $this->respuestas('SaldoReservado'));
        $this->assertCount(2, $reservas);
        $this->assertSame($reservas[0], $reservas[1]);

        $this->recibir($this->emitir($reservas[0], 1000));
        $this->recibir($this->emitir($reservas[0], 1000));

        $this->assertSame(1, ReservaSaldoModelo::query()->count());
        $this->assertSame(1, CobroModelo::query()->count());
        $emitidos = $this->respuestas('CobroEmitido');
        $this->assertCount(2, $emitidos);
        $this->assertSame($emitidos[0]['datos'], $emitidos[1]['datos']);
    }

    public function test_el_numero_sigue_el_correlativo_del_anio_y_empieza_de_nuevo_en_el_siguiente(): void
    {
        $this->crearCobro('COB-2025-0009', '1000.00', '2025-12-20');
        $this->crearCobro('COB-2026-0003', '1000.00', '2026-10-01');

        $this->recibir($this->emitir($this->crearReserva('1000.00'), 1000));
        $this->assertSame('COB-2026-0004', $this->respuestas('CobroEmitido')[0]['datos']['numero']);

        // 03:00 UTC del 1 de enero = 31 de diciembre en La Paz: todavía cuenta en 2026.
        CarbonImmutable::setTestNow('2027-01-01T03:00:00Z');
        $this->recibir($this->emitir($this->crearReserva('1000.00', planillaId: self::OTRA_PLANILLA_ID), 1000, self::OTRA_PLANILLA_ID));
        $this->assertSame('COB-2026-0005', $this->respuestas('CobroEmitido')[1]['datos']['numero']);

        // 04:00 UTC del 1 de enero = 00:00 del 1 de enero en La Paz: primer cobro de 2027.
        CarbonImmutable::setTestNow('2027-01-01T04:00:00Z');
        $tercera = '9a1a0000-0000-4000-8000-000000000005';
        $this->recibir($this->emitir($this->crearReserva('1000.00', planillaId: $tercera), 1000, $tercera));
        $emitido = $this->respuestas('CobroEmitido')[2]['datos'];
        $this->assertSame('COB-2027-0001', $emitido['numero']);
        $this->assertSame('2027-01-31', $emitido['venceEn']);
    }

    /**
     * @return array<string, mixed>
     */
    private function reservar(int|float $monto): array
    {
        return $this->comando('ReservarSaldo', ['planillaId' => self::PLANILLA_ID, 'obraId' => self::OBRA_ID, 'monto' => $monto]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emitir(string $reservaId, int|float $monto, string $planillaId = self::PLANILLA_ID): array
    {
        return $this->comando('EmitirCobro', ['planillaId' => $planillaId, 'reservaId' => $reservaId, 'monto' => $monto]);
    }

    /**
     * @return array<string, mixed>
     */
    private function liberar(string $reservaId): array
    {
        return $this->comando('LiberarReserva', ['planillaId' => self::PLANILLA_ID, 'reservaId' => $reservaId]);
    }

    /**
     * El contrato tal como lo lee `GET /contratos` (006): el saldo se calcula al leer.
     *
     * @return array<string, mixed>
     */
    private function contrato(): array
    {
        $respuesta = $this->getJson('/contratos?obraId='.self::OBRA_ID, ['Authorization' => 'Bearer '.$this->firmarConRoles(['admin'], 'erp')]);
        $respuesta->assertOk();

        return $respuesta->json(0);
    }

    /**
     * @return array{saldoDisponible: mixed, totalCobrado: mixed, totalReservado: mixed}
     */
    private function saldo(): array
    {
        $contrato = $this->contrato();

        return [
            'saldoDisponible' => $contrato['saldoDisponible'],
            'totalCobrado' => $contrato['totalCobrado'],
            'totalReservado' => $contrato['totalReservado'],
        ];
    }
}
