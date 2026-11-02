<?php

namespace Tests\Feature\Cobros;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Cobros\Infraestructura\Persistencia\EnvioCobroModelo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;

/**
 * 201: columnas del pago en `cobro` y tabla `envio_cobro` con un envío de cada tipo por cobro.
 */
class RestriccionesCobranzaTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;

    private string $cobroId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearContrato();
        $this->cobroId = $this->crearCobro('COB-2026-0001', '1000.00');
    }

    public function test_el_cobro_tiene_las_columnas_del_pago_vacias_mientras_no_se_paga(): void
    {
        $this->assertTrue(Schema::hasColumns('cobro', ['pagado_en', 'comprobante', 'pago_registrado_por', 'pago_registrado_en']));

        $cobro = CobroModelo::query()->findOrFail($this->cobroId);
        $this->assertNull($cobro->pagado_en);
        $this->assertNull($cobro->comprobante);
        $this->assertNull($cobro->pago_registrado_por);
        $this->assertNull($cobro->pago_registrado_en);
    }

    public function test_el_cobro_guarda_el_estado_pagado_con_sus_datos(): void
    {
        CobroModelo::query()->whereKey($this->cobroId)->update([
            'estado' => 'PAGADO',
            'pagado_en' => '2026-10-15',
            'comprobante' => str_repeat('9', 50),
            'pago_registrado_por' => 'a0000000-0000-4000-8000-000000000001',
            'pago_registrado_en' => '2026-10-15 14:00:00',
        ]);

        $cobro = CobroModelo::query()->findOrFail($this->cobroId);
        $this->assertSame('PAGADO', $cobro->estado);
        $this->assertSame('2026-10-15', $cobro->pagado_en?->format('Y-m-d'));
    }

    public function test_admite_un_envio_de_cada_tipo_por_cobro(): void
    {
        foreach (['NOTA_COBRO', 'RECORDATORIO_POR_VENCER', 'RECORDATORIO_VENCIDO', 'CONSTANCIA_PAGO'] as $tipo) {
            $this->envio($this->cobroId, $tipo);
        }

        $this->assertSame(4, EnvioCobroModelo::query()->where('cobro_id', $this->cobroId)->count());
    }

    public function test_no_admite_dos_envios_del_mismo_tipo_para_el_mismo_cobro(): void
    {
        $this->envio($this->cobroId, 'NOTA_COBRO');

        $this->expectException(QueryException::class);
        $this->envio($this->cobroId, 'NOTA_COBRO');
    }

    public function test_dos_cobros_pueden_tener_cada_uno_su_envio_del_mismo_tipo(): void
    {
        $otro = $this->crearCobro('COB-2026-0002', '2000.00');

        $this->envio($this->cobroId, 'NOTA_COBRO');
        $this->envio($otro, 'NOTA_COBRO');

        $this->assertSame(2, EnvioCobroModelo::query()->where('tipo', 'NOTA_COBRO')->count());
    }

    public function test_el_envio_exige_un_cobro_que_exista(): void
    {
        $this->expectException(QueryException::class);
        $this->envio((string) Str::uuid(), 'NOTA_COBRO');
    }

    private function envio(string $cobroId, string $tipo): void
    {
        EnvioCobroModelo::query()->create([
            'id' => (string) Str::uuid(),
            'cobro_id' => $cobroId,
            'tipo' => $tipo,
            'destinatario' => 'cliente1@correo.test',
            'enviado_en' => '2026-10-01 12:05:00',
            'ejecucion_n8n' => null,
        ]);
    }
}
