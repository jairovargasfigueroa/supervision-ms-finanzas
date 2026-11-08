<?php

namespace Tests\Feature\Cobros;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;

/**
 * 008: tarea diaria de las 00:10 (La Paz). Un cobro `PENDIENTE` pasa a `VENCIDO` al día siguiente de su vencimiento:
 * cuando `vence_en` es anterior a hoy en La Paz.
 */
class MarcarCobrosVencidosTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearContrato('100000.00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_marca_vencidos_solo_los_pendientes_que_vencieron_antes_de_hoy_en_la_paz(): void
    {
        // 04:10 UTC del 29 de octubre = 00:10 del 29 en La Paz: hoy = 2026-10-29.
        CarbonImmutable::setTestNow('2026-10-29T04:10:00Z');
        $ayer = $this->cobroQueVence('2026-10-28');
        $hoy = $this->cobroQueVence('2026-10-29');
        $manana = $this->cobroQueVence('2026-10-30');
        $yaVencido = $this->cobroQueVence('2026-09-01', 'VENCIDO');

        $this->artisan('cobros:marcar-vencidos')->assertSuccessful();

        $this->assertSame('VENCIDO', $this->estado($ayer));
        $this->assertSame('PENDIENTE', $this->estado($hoy), 'El que vence hoy todavía no está vencido.');
        $this->assertSame('PENDIENTE', $this->estado($manana));
        $this->assertSame('VENCIDO', $this->estado($yaVencido));
    }

    public function test_usa_la_fecha_de_la_paz_y_no_la_de_utc(): void
    {
        // 02:00 UTC del 30 de octubre: en La Paz todavía es el 29 (22:00).
        CarbonImmutable::setTestNow('2026-10-30T02:00:00Z');
        $venceEl29 = $this->cobroQueVence('2026-10-29');

        $this->artisan('cobros:marcar-vencidos')->assertSuccessful();

        $this->assertSame('PENDIENTE', $this->estado($venceEl29));
    }

    public function test_correrla_dos_veces_el_mismo_dia_no_cambia_nada_mas(): void
    {
        CarbonImmutable::setTestNow('2026-10-29T04:10:00Z');
        $ayer = $this->cobroQueVence('2026-10-28');

        $this->artisan('cobros:marcar-vencidos')->expectsOutput('Cobros marcados como vencidos: 1.')->assertSuccessful();
        $this->artisan('cobros:marcar-vencidos')->expectsOutput('Cobros marcados como vencidos: 0.')->assertSuccessful();

        $this->assertSame('VENCIDO', $this->estado($ayer));
    }

    public function test_esta_programada_cada_dia_a_las_00_10_hora_de_la_paz(): void
    {
        $eventos = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (Event $evento) => str_contains((string) $evento->command, 'cobros:marcar-vencidos'),
        ));

        $this->assertCount(1, $eventos);
        $this->assertSame('10 0 * * *', $eventos[0]->expression);
        $this->assertSame('America/La_Paz', $eventos[0]->timezone);
    }

    private function cobroQueVence(string $venceEn, string $estado = 'PENDIENTE'): string
    {
        $emitidoEn = (new \DateTimeImmutable($venceEn))->modify('-30 days')->format('Y-m-d');
        $id = $this->crearCobro('COB-2026-'.str_pad((string) (CobroModelo::query()->count() + 1), 4, '0', STR_PAD_LEFT), '1000.00', $emitidoEn);
        CobroModelo::query()->whereKey($id)->update(['estado' => $estado]);

        return $id;
    }

    private function estado(string $id): string
    {
        return CobroModelo::query()->findOrFail($id)->estado;
    }
}
