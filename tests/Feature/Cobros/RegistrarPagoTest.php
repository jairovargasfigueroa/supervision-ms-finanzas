<?php

namespace Tests\Feature\Cobros;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Compartido\Infraestructura\Mensajeria\OutboxEvento;
use App\Contratos\Aplicacion\Puertos\PublicadorEventos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;
use Tests\Utilidades\TokensPrueba;

/**
 * 201: `POST /cobros/{id}/pago`, solo `admin`. Fecha entre la emisión y hoy (La Paz), `409` si ya está pagado, `404`
 * si no existe, y `CobroPagado` en el outbox en la misma transacción.
 */
class RegistrarPagoTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;
    use TokensPrueba;

    /** `sub` del token de prueba (TokensPrueba). */
    private const SUB = '11111111-1111-1111-1111-111111111111';

    private string $cobroId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
        // 2026-10-20 10:00 en La Paz.
        CarbonImmutable::setTestNow('2026-10-20T14:00:00Z');
        $this->crearContrato('1000000.00');
        $this->cobroId = $this->crearCobro('COB-2026-0004', '41881.80', '2026-10-01', self::PLANILLA_ID);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_registra_el_pago_y_responde_el_cobro_pagado(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18', 'comprobante' => 'TRF-883412'])
            ->assertOk()
            ->assertExactJson([
                'id' => $this->cobroId,
                'numero' => 'COB-2026-0004',
                'obraId' => self::OBRA_ID,
                'planillaId' => self::PLANILLA_ID,
                'montoNeto' => 41881.8,
                'emitidoEn' => '2026-10-01',
                'venceEn' => '2026-10-31',
                'estado' => 'PAGADO',
                'pagadoEn' => '2026-10-18',
                'comprobante' => 'TRF-883412',
            ]);

        $fila = CobroModelo::query()->findOrFail($this->cobroId);
        $this->assertSame('PAGADO', $fila->estado);
        $this->assertSame('2026-10-18', $fila->pagado_en?->format('Y-m-d'));
        $this->assertSame('TRF-883412', $fila->comprobante);
        $this->assertSame(self::SUB, $fila->pago_registrado_por);
        $this->assertTrue($fila->pago_registrado_en?->equalTo(CarbonImmutable::parse('2026-10-20T14:00:00Z')));
    }

    public function test_deja_cobro_pagado_en_el_outbox(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18', 'comprobante' => 'TRF-883412'])->assertOk();

        $eventos = OutboxEvento::query()->get();
        $this->assertCount(1, $eventos);
        $evento = $eventos->first();
        $this->assertNotNull($evento);
        $this->assertSame('evento.finanzas.CobroPagado', $evento->tipo);
        $this->assertNull($evento->publicado_en);
        $sobre = $evento->payload;
        $this->assertSame('CobroPagado', $sobre['tipo']);
        $this->assertSame(1, $sobre['version']);
        $this->assertSame('finanzas', $sobre['origen']);
        $this->assertSame($this->cobroId, $sobre['correlacionId']);
        $this->assertSame([
            'cobroId' => $this->cobroId,
            'planillaId' => self::PLANILLA_ID,
            'obraId' => self::OBRA_ID,
            'numero' => 'COB-2026-0004',
            'montoNeto' => 41881.8,
            'pagadoEn' => '2026-10-18',
            'comprobante' => 'TRF-883412',
        ], $sobre['datos']);
    }

    public function test_sin_comprobante_lo_guarda_como_null(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18'])->assertOk()->assertJsonPath('comprobante', null);

        $this->assertNull(OutboxEvento::query()->firstOrFail()->payload['datos']['comprobante']);
    }

    public function test_un_comprobante_vacio_o_de_espacios_se_guarda_como_null(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18', 'comprobante' => '   '])->assertOk()->assertJsonPath('comprobante', null);
        $this->assertNull(CobroModelo::query()->findOrFail($this->cobroId)->comprobante);
    }

    public function test_quita_los_espacios_de_los_extremos_del_comprobante(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18', 'comprobante' => '  TRF-1  '])->assertOk()->assertJsonPath('comprobante', 'TRF-1');
    }

    public function test_admite_un_comprobante_de_50_caracteres(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18', 'comprobante' => str_repeat('A', 50)])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     */
    #[DataProvider('cuerposInvalidos')]
    public function test_datos_mal_formados_responden_400_sin_pagar(array $cuerpo, string $campo): void
    {
        $this->pagar($cuerpo)
            ->assertStatus(400)
            ->assertJsonPath('codigo', 'DATOS_INVALIDOS')
            ->assertJsonPath('errores.0.campo', $campo);

        $this->assertSame('PENDIENTE', CobroModelo::query()->findOrFail($this->cobroId)->estado);
        $this->assertSame(0, OutboxEvento::query()->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function cuerposInvalidos(): array
    {
        return [
            'sin fecha' => [['comprobante' => 'X'], 'pagadoEn'],
            'fecha vacía' => [['pagadoEn' => ''], 'pagadoEn'],
            'fecha con otro formato' => [['pagadoEn' => '18/10/2026'], 'pagadoEn'],
            'fecha que no existe' => [['pagadoEn' => '2026-02-30'], 'pagadoEn'],
            'fecha con hora' => [['pagadoEn' => '2026-10-18T10:00:00Z'], 'pagadoEn'],
            'fecha que no es texto' => [['pagadoEn' => 20261018], 'pagadoEn'],
            'comprobante de 51 caracteres' => [['pagadoEn' => '2026-10-18', 'comprobante' => str_repeat('A', 51)], 'comprobante'],
            'comprobante que no es texto' => [['pagadoEn' => '2026-10-18', 'comprobante' => 123], 'comprobante'],
        ];
    }

    public function test_admite_pagar_hoy_en_la_paz(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-20'])->assertOk()->assertJsonPath('pagadoEn', '2026-10-20');
    }

    public function test_admite_pagar_el_dia_de_la_emision(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-01'])->assertOk()->assertJsonPath('pagadoEn', '2026-10-01');
    }

    #[DataProvider('fechasInvalidas')]
    public function test_una_fecha_futura_o_anterior_a_la_emision_responde_422(string $fecha): void
    {
        $this->pagar(['pagadoEn' => $fecha])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'FECHA_PAGO_INVALIDA');

        $this->assertSame('PENDIENTE', CobroModelo::query()->findOrFail($this->cobroId)->estado);
        $this->assertSame(0, OutboxEvento::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fechasInvalidas(): array
    {
        return [
            'mañana' => ['2026-10-21'],
            'el día antes de la emisión' => ['2026-09-30'],
        ];
    }

    public function test_hoy_es_la_fecha_de_la_paz_y_no_la_de_utc(): void
    {
        // 2026-10-21 02:00 UTC es todavía 2026-10-20 22:00 en La Paz: el 21 es futuro.
        CarbonImmutable::setTestNow('2026-10-21T02:00:00Z');

        $this->pagar(['pagadoEn' => '2026-10-21'])->assertStatus(422)->assertJsonPath('codigo', 'FECHA_PAGO_INVALIDA');
        $this->pagar(['pagadoEn' => '2026-10-20'])->assertOk();
    }

    public function test_un_cobro_vencido_tambien_se_paga(): void
    {
        CarbonImmutable::setTestNow('2026-11-05T14:00:00Z');
        CobroModelo::query()->whereKey($this->cobroId)->update(['estado' => 'VENCIDO']);

        $this->pagar(['pagadoEn' => '2026-11-03'])->assertOk()->assertJsonPath('estado', 'PAGADO');
    }

    public function test_pagar_un_cobro_ya_pagado_responde_409_y_no_lo_cambia(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18', 'comprobante' => 'PRIMERO'])->assertOk();

        $this->pagar(['pagadoEn' => '2026-10-19', 'comprobante' => 'SEGUNDO'])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'COBRO_YA_PAGADO');

        $fila = CobroModelo::query()->findOrFail($this->cobroId);
        $this->assertSame('2026-10-18', $fila->pagado_en?->format('Y-m-d'));
        $this->assertSame('PRIMERO', $fila->comprobante);
        $this->assertSame(1, OutboxEvento::query()->count());
    }

    public function test_el_cobro_ya_pagado_se_informa_antes_que_la_fecha_invalida(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18'])->assertOk();

        $this->pagar(['pagadoEn' => '2027-01-01'])->assertStatus(409)->assertJsonPath('codigo', 'COBRO_YA_PAGADO');
    }

    public function test_un_cobro_que_no_existe_responde_404(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18'], 'c0b00000-0000-4000-8000-0000000000ff')
            ->assertNotFound()
            ->assertJsonPath('codigo', 'COBRO_NO_ENCONTRADO');
    }

    public function test_un_id_que_no_es_uuid_responde_404_cobro_no_encontrado(): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18'], 'no-es-un-id')
            ->assertNotFound()
            ->assertJsonPath('codigo', 'COBRO_NO_ENCONTRADO');
    }

    public function test_si_falla_el_outbox_no_se_guarda_el_pago(): void
    {
        $this->app->instance(PublicadorEventos::class, new class implements PublicadorEventos
        {
            public function publicar(string $tipo, array $datos, string $correlacionId): void
            {
                throw new RuntimeException('Falla el outbox.');
            }
        });

        $this->pagar(['pagadoEn' => '2026-10-18'])->assertStatus(500);

        $fila = CobroModelo::query()->findOrFail($this->cobroId);
        $this->assertSame('PENDIENTE', $fila->estado);
        $this->assertNull($fila->pagado_en);
    }

    /**
     * @param  list<string>  $roles
     */
    #[DataProvider('rolesSinAcceso')]
    public function test_los_roles_distintos_de_admin_reciben_403(array $roles, string $azp): void
    {
        $this->pagar(['pagadoEn' => '2026-10-18'], null, $roles, $azp)
            ->assertForbidden()
            ->assertJsonPath('codigo', 'SIN_PERMISO');

        $this->assertSame('PENDIENTE', CobroModelo::query()->findOrFail($this->cobroId)->estado);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function rolesSinAcceso(): array
    {
        return [
            'gerente' => [['gerente'], 'erp'],
            'residente' => [['residente'], 'erp'],
            'maestro' => [['maestro'], 'app-obra'],
            'almacenero' => [['almacenero'], 'app-obra'],
            'cliente' => [['cliente'], 'portal'],
            'admin desde el portal' => [['admin', 'cliente'], 'portal'],
            'n8n (automatizacion y gerente)' => [['automatizacion', 'gerente'], 'n8n'],
        ];
    }

    public function test_sin_token_responde_401(): void
    {
        $this->postJson('/cobros/'.$this->cobroId.'/pago', ['pagadoEn' => '2026-10-18'])
            ->assertUnauthorized()
            ->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @param  list<string>  $roles
     */
    private function pagar(array $cuerpo, ?string $id = null, array $roles = ['admin'], string $azp = 'erp'): TestResponse
    {
        return $this->postJson(
            '/cobros/'.($id ?? $this->cobroId).'/pago',
            $cuerpo,
            ['Authorization' => 'Bearer '.$this->firmarConRoles($roles, $azp)],
        );
    }
}
