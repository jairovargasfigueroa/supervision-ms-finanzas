<?php

namespace Tests\Feature\Cobros;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;
use Tests\Utilidades\TokensPrueba;

/**
 * 201 · pruebas de la spec del lado de Finanzas: la vida de un cobro en la cobranza automática, con n8n simulado por
 * sus llamadas REST (rol `automatizacion` y `gerente`, `azp = n8n`). La saga emite el cobro; n8n anota la nota de cobro
 * una sola vez; «Recordar cobros» lo pide por vencer y después vencido, una vez cada uno; administración registra el
 * pago, que publica `CobroPagado`; n8n anota la constancia y el cobro ya no recibe recordatorios.
 */
class CobranzaAutomaticaTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;
    use TokensPrueba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCanal();
        $this->simularKeycloak();
        $this->crearContrato('500000.00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_de_la_emision_al_pago_con_un_correo_de_cada_tipo(): void
    {
        // La saga emite el cobro el 2026-10-02 a las 10:00 de La Paz: vence el 2026-11-01.
        CarbonImmutable::setTestNow('2026-10-02T14:00:00Z');
        $cobroId = $this->emitirCobro('41881.80');
        $emitido = $this->respuestas('CobroEmitido')[0];
        $this->assertSame('2026-11-01', $emitido['datos']['venceEn']);

        // «Enviar nota de cobro»: el cobro no tiene NOTA_COBRO, n8n pide el PDF, envía y anota.
        $this->n8n('GET', '/cobros/'.$cobroId)->assertOk()->assertJsonPath('envios', []);
        $this->n8n('POST', '/cobros/'.$cobroId.'/nota-cobro', $this->planilla('41881.80'))->assertOk();
        $this->anotar($cobroId, 'NOTA_COBRO')->assertCreated();
        // El aviso llega dos veces: n8n ve el envío y termina; si anotara de nuevo, se le devuelve el mismo (criterio 10).
        $this->n8n('GET', '/cobros/'.$cobroId)->assertOk()->assertJsonPath('envios.0.tipo', 'NOTA_COBRO');
        $this->anotar($cobroId, 'NOTA_COBRO')->assertOk();

        // Hoy + 3 = vencimiento: recordatorio «por vencer», una sola vez.
        CarbonImmutable::setTestNow('2026-10-29T12:00:00Z');
        $this->recordatorios()->assertJsonPath('porVencer.0.id', $cobroId)->assertJsonPath('porVencer.0.diasParaVencer', 3);
        $this->anotar($cobroId, 'RECORDATORIO_POR_VENCER')->assertCreated();
        $this->recordatorios()->assertJsonCount(0, 'porVencer')->assertJsonCount(0, 'vencidos');

        // Venció: la tarea de las 00:10 lo pasa a VENCIDO y a las 08:00 se le recuerda «vencido», una sola vez.
        CarbonImmutable::setTestNow('2026-11-03T04:10:00Z');
        $this->artisan('cobros:marcar-vencidos')->assertSuccessful();
        CarbonImmutable::setTestNow('2026-11-03T12:00:00Z');
        $this->recordatorios()->assertJsonPath('vencidos.0.id', $cobroId)->assertJsonPath('vencidos.0.estado', 'VENCIDO');
        $this->anotar($cobroId, 'RECORDATORIO_VENCIDO')->assertCreated();
        $this->recordatorios()->assertJsonCount(0, 'vencidos');

        // Administración registra el pago: PAGADO y CobroPagado para n8n e IA.
        $this->postJson('/cobros/'.$cobroId.'/pago', ['pagadoEn' => '2026-11-02', 'comprobante' => 'TRF-883412'], $this->admin())
            ->assertOk()
            ->assertJsonPath('estado', 'PAGADO');
        $pagado = $this->respuestas('CobroPagado');
        $this->assertCount(1, $pagado);
        $this->assertSame([
            'cobroId' => $cobroId,
            'planillaId' => self::PLANILLA_ID,
            'obraId' => self::OBRA_ID,
            'numero' => $emitido['datos']['numero'],
            'montoNeto' => 41881.8,
            'pagadoEn' => '2026-11-02',
            'comprobante' => 'TRF-883412',
        ], $pagado[0]['datos']);
        $this->postJson('/cobros/'.$cobroId.'/pago', ['pagadoEn' => '2026-11-02'], $this->admin())
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'COBRO_YA_PAGADO');

        // «Enviar constancia de pago», una sola vez (unos minutos después).
        CarbonImmutable::setTestNow('2026-11-03T12:05:00Z');
        $this->anotar($cobroId, 'CONSTANCIA_PAGO')->assertCreated();
        $this->anotar($cobroId, 'CONSTANCIA_PAGO')->assertOk();

        // Un PAGADO no vuelve a vencerse ni recibe recordatorios, y sigue en lo cobrado del contrato.
        CarbonImmutable::setTestNow('2026-12-15T12:00:00Z');
        $this->artisan('cobros:marcar-vencidos')->assertSuccessful();
        $this->recordatorios()->assertJsonCount(0, 'porVencer')->assertJsonCount(0, 'vencidos');
        $this->getJson('/cobros/resumen', $this->admin())
            ->assertExactJson(['vencidos' => ['cantidad' => 0, 'monto' => 0], 'porVencer30Dias' => ['cantidad' => 0, 'monto' => 0]]);
        $this->getJson('/contratos?obraId='.self::OBRA_ID, $this->admin())->assertJsonPath('0.totalCobrado', 41881.8);

        // El ERP ve los cuatro correos en orden.
        $this->getJson('/cobros/'.$cobroId, $this->admin())
            ->assertOk()
            ->assertJsonPath('estado', 'PAGADO')
            ->assertJsonPath('pagadoEn', '2026-11-02')
            ->assertJsonPath('comprobante', 'TRF-883412')
            ->assertJsonPath('envios.*.tipo', ['NOTA_COBRO', 'RECORDATORIO_POR_VENCER', 'RECORDATORIO_VENCIDO', 'CONSTANCIA_PAGO']);
    }

    /** Reserva y emite el cobro por la saga (Obras simulado); devuelve su id. */
    private function emitirCobro(string $monto): string
    {
        $this->recibir($this->comando('ReservarSaldo', ['planillaId' => self::PLANILLA_ID, 'obraId' => self::OBRA_ID, 'monto' => $monto]));
        $reservaId = $this->respuestas('SaldoReservado')[0]['datos']['reservaId'];
        $this->recibir($this->comando('EmitirCobro', ['planillaId' => self::PLANILLA_ID, 'reservaId' => $reservaId, 'monto' => $monto]));

        return $this->respuestas('CobroEmitido')[0]['datos']['cobroId'];
    }

    /**
     * La planilla como la manda n8n: una línea, sin anticipo ni retención para que el neto sea el subtotal.
     *
     * @return array<string, mixed>
     */
    private function planilla(string $neto): array
    {
        return [
            'cliente' => ['nombre' => 'Roberto Justiniano Paz'],
            'obra' => ['id' => self::OBRA_ID, 'nombre' => 'Edificio Residencial Las Palmeras'],
            'planilla' => [
                'id' => self::PLANILLA_ID,
                'numero' => 1,
                'periodo' => '2026-09',
                'lineas' => [['codigo' => 'P-01', 'nombre' => 'Excavación', 'unidad' => 'm3', 'cantidadPeriodo' => 1, 'precioUnitario' => $neto, 'monto' => $neto]],
                'subtotal' => $neto,
                'descuentoAnticipo' => 0,
                'retencion' => 0,
                'neto' => $neto,
                'anticipoPct' => 0,
                'retencionPct' => 0,
            ],
        ];
    }

    private function anotar(string $cobroId, string $tipo): TestResponse
    {
        return $this->n8n('POST', '/cobros/'.$cobroId.'/envios', [
            'tipo' => $tipo,
            'destinatario' => 'cliente1@correo.test',
            'ejecucionN8n' => '1532',
        ]);
    }

    private function recordatorios(): TestResponse
    {
        return $this->n8n('GET', '/cobros/recordatorios')->assertOk();
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     */
    private function n8n(string $metodo, string $ruta, array $cuerpo = []): TestResponse
    {
        return $this->json($metodo, $ruta, $cuerpo, ['Authorization' => 'Bearer '.$this->firmarConRoles(['automatizacion', 'gerente'], 'n8n')]);
    }

    /**
     * @return array<string, string>
     */
    private function admin(): array
    {
        return ['Authorization' => 'Bearer '.$this->firmarConRoles(['admin'])];
    }
}
