<?php

namespace Tests\Feature\Mensajeria;

use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Compartido\Infraestructura\Mensajeria\OutboxEvento;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Utilidades\CanalMensajesFalso;

/**
 * `mensajeria:publicar`: envía el outbox al exchange `plataforma` y marca lo publicado (002).
 */
class PublicadorOutboxTest extends TestCase
{
    use RefreshDatabase;

    private CanalMensajesFalso $canal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canal = new CanalMensajesFalso;
        $this->app->instance(CanalMensajes::class, $this->canal);
    }

    public function test_publica_los_pendientes_en_orden_con_su_clave_y_su_id(): void
    {
        CarbonImmutable::setTestNow('2026-10-29 10:00:00');
        $primero = Sobre::nuevo('ContratoRegistrado', ['obraId' => 'o-1', 'contratoId' => 'c-1'], 'corr-1');
        OutboxEvento::registrar('evento.finanzas.ContratoRegistrado', $primero);
        CarbonImmutable::setTestNow('2026-10-29 10:00:01');
        $segundo = Sobre::nuevo('ContratoRechazado', ['obraId' => 'o-2', 'motivo' => 'El monto del contrato debe ser mayor a 0'], 'corr-2');
        OutboxEvento::registrar('evento.finanzas.ContratoRechazado', $segundo);

        $this->artisan('mensajeria:publicar', ['--una-vez' => true])->assertSuccessful();

        $this->assertCount(2, $this->canal->publicados);
        [$p1, $p2] = $this->canal->publicados;
        $this->assertSame('plataforma', $p1['exchange']);
        $this->assertSame('evento.finanzas.ContratoRegistrado', $p1['claveRuteo']);
        $this->assertSame($primero->id, $p1['idMensaje']);
        $this->assertEquals($primero->aArreglo(), json_decode($p1['cuerpo'], true));
        $this->assertSame('evento.finanzas.ContratoRechazado', $p2['claveRuteo']);
        // El texto viaja sin escapar (UTF-8).
        $this->assertStringContainsString('"motivo":"El monto del contrato debe ser mayor a 0"', $p2['cuerpo']);

        $this->assertSame(0, OutboxEvento::query()->whereNull('publicado_en')->count());
    }

    public function test_lo_ya_publicado_no_se_vuelve_a_publicar(): void
    {
        OutboxEvento::registrar('evento.finanzas.ContratoRegistrado', Sobre::nuevo('ContratoRegistrado', ['obraId' => 'o-1'], 'corr-1'));

        $this->artisan('mensajeria:publicar', ['--una-vez' => true])->assertSuccessful();
        $this->artisan('mensajeria:publicar', ['--una-vez' => true])->assertSuccessful();

        $this->assertCount(1, $this->canal->publicados);
    }

    public function test_el_sobre_nuevo_lleva_los_campos_comunes(): void
    {
        $sobre = Sobre::nuevo('ContratoRegistrado', ['obraId' => 'o-1'], 'corr-1');

        $arreglo = $sobre->aArreglo();
        $this->assertSame(['id', 'tipo', 'version', 'ocurridoEn', 'origen', 'correlacionId', 'datos'], array_keys($arreglo));
        $this->assertSame(1, $arreglo['version']);
        $this->assertSame('finanzas', $arreglo['origen']);
        $this->assertSame('corr-1', $arreglo['correlacionId']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $arreglo['ocurridoEn']);
    }
}
