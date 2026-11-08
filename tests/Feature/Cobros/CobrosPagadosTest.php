<?php

namespace Tests\Feature\Cobros;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Cobros\Infraestructura\Persistencia\EnvioCobroModelo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;
use Tests\Utilidades\TokensPrueba;

/**
 * 201: `GET /cobros` y `GET /cobros/{id}` con `pagadoEn`, `comprobante` y `envios`; filtro `estado=PAGADO`. Un cobro
 * `PAGADO` sigue contando en `totalCobrado` del contrato, pero no se vence ni cuenta en el resumen del tablero.
 */
class CobrosPagadosTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;
    use TokensPrueba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
        // 2026-10-20 10:00 en La Paz.
        CarbonImmutable::setTestNow('2026-10-20T14:00:00Z');
        $this->crearContrato('1000000.00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_el_cobro_trae_sus_envios_ordenados_por_fecha_en_utc(): void
    {
        $id = $this->crearCobro('COB-2026-0001', '1000.00', '2026-09-01');
        // Se guardan con zona: la de La Paz se informa en UTC.
        $this->envio($id, 'RECORDATORIO_VENCIDO', '2026-10-02 08:00:12-04:00', 'cliente1@correo.test');
        $this->envio($id, 'NOTA_COBRO', '2026-09-01 10:05:00-04:00', 'cliente1@correo.test');

        $this->consultar('/cobros/'.$id)
            ->assertOk()
            ->assertJsonPath('pagadoEn', null)
            ->assertJsonPath('comprobante', null)
            ->assertJsonPath('envios', [
                ['tipo' => 'NOTA_COBRO', 'destinatario' => 'cliente1@correo.test', 'enviadoEn' => '2026-09-01T14:05:00Z'],
                ['tipo' => 'RECORDATORIO_VENCIDO', 'destinatario' => 'cliente1@correo.test', 'enviadoEn' => '2026-10-02T12:00:12Z'],
            ]);
    }

    public function test_la_lista_trae_los_envios_de_cada_cobro_sin_mezclarlos(): void
    {
        $uno = $this->crearCobro('COB-2026-0001', '1000.00', '2026-09-01');
        $dos = $this->crearCobro('COB-2026-0002', '2000.00', '2026-09-10');
        $tres = $this->crearCobro('COB-2026-0003', '3000.00', '2026-09-20');
        $this->envio($uno, 'NOTA_COBRO', '2026-09-01 14:05:00+00:00', 'cliente1@correo.test');
        $this->envio($dos, 'NOTA_COBRO', '2026-09-10 14:05:00+00:00', 'demo1@constructora-andina.test');
        $this->envio($dos, 'RECORDATORIO_POR_VENCER', '2026-10-07 12:00:00+00:00', 'demo1@constructora-andina.test');

        $items = $this->consultar('/cobros?obraId='.self::OBRA_ID)->assertOk()->json('items');

        $this->assertSame([$uno, $dos, $tres], array_column($items, 'id'));
        $this->assertSame(['NOTA_COBRO'], array_column($items[0]['envios'], 'tipo'));
        $this->assertSame(['NOTA_COBRO', 'RECORDATORIO_POR_VENCER'], array_column($items[1]['envios'], 'tipo'));
        $this->assertSame(['demo1@constructora-andina.test'], array_values(array_unique(array_column($items[1]['envios'], 'destinatario'))));
        $this->assertSame([], $items[2]['envios']);
    }

    public function test_el_cobro_pagado_trae_su_fecha_y_comprobante(): void
    {
        $id = $this->crearCobro('COB-2026-0001', '1000.00', '2026-09-01');
        $this->pagado($id, '2026-10-05', 'TRF-1');

        $this->consultar('/cobros/'.$id)
            ->assertOk()
            ->assertJsonPath('estado', 'PAGADO')
            ->assertJsonPath('pagadoEn', '2026-10-05')
            ->assertJsonPath('comprobante', 'TRF-1');
    }

    public function test_filtra_por_estado_pagado(): void
    {
        $pagado = $this->crearCobro('COB-2026-0001', '1000.00', '2026-09-01');
        $this->pagado($pagado, '2026-10-05');
        $this->crearCobro('COB-2026-0002', '2000.00', '2026-09-10');

        $this->consultar('/cobros?estado=PAGADO')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $pagado);
        $this->consultar('/cobros?estado=PENDIENTE')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_el_total_cobrado_del_contrato_cuenta_tambien_los_pagados(): void
    {
        $pagado = $this->crearCobro('COB-2026-0001', '1000.00', '2026-09-01');
        $this->pagado($pagado, '2026-10-05');
        $vencido = $this->crearCobro('COB-2026-0002', '2000.00', '2026-08-01');
        CobroModelo::query()->whereKey($vencido)->update(['estado' => 'VENCIDO']);
        $this->crearCobro('COB-2026-0003', '3000.00', '2026-10-01');

        $this->consultar('/contratos?obraId='.self::OBRA_ID)
            ->assertOk()
            ->assertJsonPath('0.totalCobrado', 6000)
            ->assertJsonPath('0.saldoDisponible', 994000);
    }

    public function test_pagar_no_cambia_el_saldo_del_contrato(): void
    {
        $id = $this->crearCobro('COB-2026-0001', '1000.00', '2026-10-01');
        $antes = $this->consultar('/contratos?obraId='.self::OBRA_ID)->json('0');

        $this->postJson('/cobros/'.$id.'/pago', ['pagadoEn' => '2026-10-20'], $this->encabezados())->assertOk();

        $this->assertSame($antes, $this->consultar('/contratos?obraId='.self::OBRA_ID)->json('0'));
    }

    public function test_la_tarea_de_vencidos_no_vence_un_cobro_pagado(): void
    {
        // Vencen el 2026-10-01, antes de hoy (2026-10-20).
        $pagado = $this->crearCobro('COB-2026-0001', '1000.00', '2026-09-01');
        $this->pagado($pagado, '2026-09-15');
        $pendiente = $this->crearCobro('COB-2026-0002', '2000.00', '2026-09-01');

        $this->artisan('cobros:marcar-vencidos')->assertSuccessful();

        $this->assertSame('PAGADO', CobroModelo::query()->findOrFail($pagado)->estado);
        $this->assertSame('VENCIDO', CobroModelo::query()->findOrFail($pendiente)->estado);
    }

    public function test_el_resumen_del_tablero_no_cuenta_los_pagados(): void
    {
        // Pagados: uno ya vencido y otro que vencería en 10 días.
        $vencidoPagado = $this->crearCobro('COB-2026-0001', '1000.00', '2026-09-01');
        $this->pagado($vencidoPagado, '2026-09-15');
        $porVencerPagado = $this->crearCobro('COB-2026-0002', '2000.00', '2026-10-01');
        $this->pagado($porVencerPagado, '2026-10-10');
        // Los que sí cuentan.
        $this->crearCobro('COB-2026-0003', '300.00', '2026-09-02');
        $this->crearCobro('COB-2026-0004', '400.00', '2026-10-02');

        $this->consultar('/cobros/resumen')
            ->assertOk()
            ->assertExactJson([
                'vencidos' => ['cantidad' => 1, 'monto' => 300],
                'porVencer30Dias' => ['cantidad' => 1, 'monto' => 400],
            ]);
    }

    private function pagado(string $id, string $pagadoEn, ?string $comprobante = null): void
    {
        CobroModelo::query()->whereKey($id)->update([
            'estado' => 'PAGADO',
            'pagado_en' => $pagadoEn,
            'comprobante' => $comprobante,
            'pago_registrado_por' => 'a0000000-0000-4000-8000-000000000001',
            'pago_registrado_en' => $pagadoEn.' 15:00:00+00:00',
        ]);
    }

    private function envio(string $cobroId, string $tipo, string $enviadoEn, string $destinatario): void
    {
        EnvioCobroModelo::query()->insert([
            'id' => (string) Str::uuid(),
            'cobro_id' => $cobroId,
            'tipo' => $tipo,
            'destinatario' => $destinatario,
            'enviado_en' => $enviadoEn,
            'ejecucion_n8n' => null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function encabezados(): array
    {
        return ['Authorization' => 'Bearer '.$this->firmarConRoles(['admin'])];
    }

    private function consultar(string $uri): TestResponse
    {
        return $this->getJson($uri, $this->encabezados());
    }
}
