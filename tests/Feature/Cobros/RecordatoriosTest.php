<?php

namespace Tests\Feature\Cobros;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Cobros\Infraestructura\Persistencia\EnvioCobroModelo;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;
use Tests\Utilidades\TokensPrueba;

/**
 * 201: `GET /cobros/recordatorios` (rol `automatizacion`, lo llama n8n). Por vencer: `PENDIENTE` que vence entre hoy y
 * hoy + 3 (La Paz) sin recordatorio «por vencer»; vencidos: `VENCIDO` o `PENDIENTE` vencido, sin recordatorio
 * «vencido». Un `PAGADO` no aparece.
 */
class RecordatoriosTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;
    use TokensPrueba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
        // 2026-10-20 08:00 en La Paz, la hora del flujo «Recordar cobros».
        CarbonImmutable::setTestNow('2026-10-20T12:00:00Z');
        $this->crearContrato('1000000.00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_sin_cobros_responde_listas_vacias_con_la_fecha_de_hoy(): void
    {
        $this->recordatorios()
            ->assertOk()
            ->assertExactJson(['hoy' => '2026-10-20', 'porVencer' => [], 'vencidos' => []]);
    }

    public function test_devuelve_el_formato_del_contrato(): void
    {
        $porVencer = $this->cobroQueVence('COB-2026-0002', '2026-10-22', planillaId: self::PLANILLA_ID);
        $vencido = $this->cobroQueVence('COB-2026-0001', '2026-10-18', estado: 'VENCIDO');
        $planillaVencido = CobroModelo::query()->findOrFail($vencido)->planilla_id;

        $this->recordatorios()
            ->assertOk()
            ->assertExactJson([
                'hoy' => '2026-10-20',
                'porVencer' => [[
                    'id' => $porVencer,
                    'numero' => 'COB-2026-0002',
                    'obraId' => self::OBRA_ID,
                    'planillaId' => self::PLANILLA_ID,
                    'montoNeto' => 1000.5,
                    'emitidoEn' => '2026-09-22',
                    'venceEn' => '2026-10-22',
                    'estado' => 'PENDIENTE',
                    'diasParaVencer' => 2,
                ]],
                'vencidos' => [[
                    'id' => $vencido,
                    'numero' => 'COB-2026-0001',
                    'obraId' => self::OBRA_ID,
                    'planillaId' => $planillaVencido,
                    'montoNeto' => 1000.5,
                    'emitidoEn' => '2026-09-18',
                    'venceEn' => '2026-10-18',
                    'estado' => 'VENCIDO',
                    'diasVencido' => 2,
                ]],
            ]);
    }

    public function test_bordes_de_las_fechas(): void
    {
        $this->cobroQueVence('COB-2026-0001', '2026-10-20');                     // vence hoy: por vencer, 0 días
        $this->cobroQueVence('COB-2026-0002', '2026-10-23');                     // hoy + 3: por vencer
        $this->cobroQueVence('COB-2026-0003', '2026-10-24');                     // hoy + 4: todavía no
        $this->cobroQueVence('COB-2026-0004', '2026-10-19');                     // ayer, todavía PENDIENTE: vencido
        $this->cobroQueVence('COB-2026-0005', '2026-10-01', estado: 'VENCIDO');  // vencido guardado

        $respuesta = $this->recordatorios()->assertOk();

        $this->assertSame(['COB-2026-0001', 'COB-2026-0002'], array_column($respuesta->json('porVencer'), 'numero'));
        $this->assertSame([0, 3], array_column($respuesta->json('porVencer'), 'diasParaVencer'));
        $this->assertSame(['COB-2026-0005', 'COB-2026-0004'], array_column($respuesta->json('vencidos'), 'numero'));
        $this->assertSame([19, 1], array_column($respuesta->json('vencidos'), 'diasVencido'));
    }

    public function test_hoy_es_la_fecha_de_la_paz_y_no_la_de_utc(): void
    {
        // 2026-10-21 02:00 UTC es todavía 2026-10-20 22:00 en La Paz.
        CarbonImmutable::setTestNow('2026-10-21T02:00:00Z');
        $this->cobroQueVence('COB-2026-0001', '2026-10-20');
        $this->cobroQueVence('COB-2026-0002', '2026-10-24');

        $this->recordatorios()
            ->assertOk()
            ->assertJsonPath('hoy', '2026-10-20')
            ->assertJsonPath('porVencer.0.numero', 'COB-2026-0001')
            ->assertJsonCount(1, 'porVencer')
            ->assertJsonCount(0, 'vencidos');
    }

    public function test_ordena_por_vencimiento_y_numero(): void
    {
        $this->cobroQueVence('COB-2026-0003', '2026-10-21');
        $this->cobroQueVence('COB-2026-0002', '2026-10-22');
        $this->cobroQueVence('COB-2026-0001', '2026-10-22');

        $this->assertSame(
            ['COB-2026-0003', 'COB-2026-0001', 'COB-2026-0002'],
            array_column($this->recordatorios()->json('porVencer'), 'numero'),
        );
    }

    public function test_no_repite_un_recordatorio_ya_enviado_de_ese_tipo(): void
    {
        $porVencer = $this->cobroQueVence('COB-2026-0001', '2026-10-21');
        $vencido = $this->cobroQueVence('COB-2026-0002', '2026-10-10', estado: 'VENCIDO');
        $this->envio($porVencer, 'RECORDATORIO_POR_VENCER');
        $this->envio($vencido, 'RECORDATORIO_VENCIDO');

        $this->recordatorios()
            ->assertOk()
            ->assertJsonCount(0, 'porVencer')
            ->assertJsonCount(0, 'vencidos');
    }

    public function test_cuenta_solo_el_envio_de_su_propio_tipo(): void
    {
        // Ya se le recordó «por vencer» y ahora venció: le toca el recordatorio «vencido».
        $vencido = $this->cobroQueVence('COB-2026-0001', '2026-10-18');
        $this->envio($vencido, 'NOTA_COBRO');
        $this->envio($vencido, 'RECORDATORIO_POR_VENCER');
        // Otro envío de otro tipo no lo saca de «por vencer».
        $porVencer = $this->cobroQueVence('COB-2026-0002', '2026-10-21');
        $this->envio($porVencer, 'NOTA_COBRO');
        $this->envio($porVencer, 'RECORDATORIO_VENCIDO');

        $this->recordatorios()
            ->assertOk()
            ->assertJsonPath('porVencer.0.id', $porVencer)
            ->assertJsonPath('vencidos.0.id', $vencido);
    }

    public function test_un_cobro_pagado_no_recibe_recordatorios(): void
    {
        $pagadoPorVencer = $this->cobroQueVence('COB-2026-0001', '2026-10-21');
        $pagadoVencido = $this->cobroQueVence('COB-2026-0002', '2026-10-10');
        CobroModelo::query()->whereIn('id', [$pagadoPorVencer, $pagadoVencido])->update([
            'estado' => 'PAGADO',
            'pagado_en' => '2026-10-15',
        ]);

        $this->recordatorios()
            ->assertOk()
            ->assertJsonCount(0, 'porVencer')
            ->assertJsonCount(0, 'vencidos');
    }

    public function test_incluye_los_cobros_de_todas_las_obras(): void
    {
        $otroContrato = 'c0a10000-0000-4000-8000-000000000002';
        $this->crearContrato('500000.00', $otroContrato, '0b1a0000-0000-4000-8000-000000000002');
        $this->cobroQueVence('COB-2026-0001', '2026-10-21');
        $this->cobroQueVence('COB-2026-0002', '2026-10-22', contratoId: $otroContrato);

        $this->assertSame(
            [self::OBRA_ID, '0b1a0000-0000-4000-8000-000000000002'],
            array_column($this->recordatorios()->json('porVencer'), 'obraId'),
        );
    }

    /**
     * @param  list<string>  $roles
     */
    #[DataProvider('rolesSinAcceso')]
    public function test_los_roles_distintos_de_automatizacion_reciben_403(array $roles, string $azp): void
    {
        $this->recordatorios($roles, $azp)
            ->assertForbidden()
            ->assertJsonPath('codigo', 'SIN_PERMISO');
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function rolesSinAcceso(): array
    {
        return [
            'admin' => [['admin'], 'erp'],
            'gerente' => [['gerente'], 'erp'],
            'gerente de servicio (ia-reportes)' => [['gerente'], 'ia-reportes'],
            'agente_ia' => [['agente_ia'], 'ia-agentes'],
            'automatizacion desde el ERP' => [['automatizacion', 'admin'], 'erp'],
            'cliente' => [['cliente'], 'portal'],
        ];
    }

    public function test_sin_token_responde_401(): void
    {
        $this->getJson('/cobros/recordatorios')->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_automatizacion_no_entra_en_las_demas_rutas(): void
    {
        $cobro = $this->cobroQueVence('COB-2026-0001', '2026-10-21');
        $encabezados = ['Authorization' => 'Bearer '.$this->firmarConRoles(['automatizacion'], 'n8n')];

        $this->getJson('/cobros', $encabezados)->assertForbidden();
        $this->getJson('/cobros/'.$cobro, $encabezados)->assertForbidden();
        $this->getJson('/cobros/resumen', $encabezados)->assertForbidden();
        $this->getJson('/contratos?obraId='.self::OBRA_ID, $encabezados)->assertForbidden();
        $this->postJson('/cobros/'.$cobro.'/pago', ['pagadoEn' => '2026-10-20'], $encabezados)->assertForbidden();
    }

    private function cobroQueVence(
        string $numero,
        string $venceEn,
        string $estado = 'PENDIENTE',
        string $planillaId = '',
        string $contratoId = self::CONTRATO_ID,
    ): string {
        $emitidoEn = (new DateTimeImmutable($venceEn))->modify('-30 days')->format('Y-m-d');
        $id = $this->crearCobro($numero, '1000.50', $emitidoEn, $planillaId, $contratoId);
        if ($estado !== 'PENDIENTE') {
            CobroModelo::query()->whereKey($id)->update(['estado' => $estado]);
        }

        return $id;
    }

    private function envio(string $cobroId, string $tipo): void
    {
        EnvioCobroModelo::query()->insert([
            'id' => (string) Str::uuid(),
            'cobro_id' => $cobroId,
            'tipo' => $tipo,
            'destinatario' => 'cliente1@correo.test',
            'enviado_en' => '2026-10-10 12:00:00+00:00',
            'ejecucion_n8n' => null,
        ]);
    }

    /**
     * @param  list<string>  $roles
     */
    private function recordatorios(array $roles = ['automatizacion', 'gerente'], string $azp = 'n8n'): TestResponse
    {
        return $this->getJson('/cobros/recordatorios', ['Authorization' => 'Bearer '.$this->firmarConRoles($roles, $azp)]);
    }
}
