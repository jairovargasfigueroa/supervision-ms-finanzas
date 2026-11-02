<?php

namespace Tests\Feature\Cobros;

use App\Cobros\Infraestructura\Persistencia\EnvioCobroModelo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;
use Tests\Utilidades\TokensPrueba;

/**
 * 201: `POST /cobros/{id}/envios` (rol `automatizacion`): n8n anota cada correo enviado. Idempotente por
 * (`cobro_id`, `tipo`): `201` si lo crea, `200` con el existente si ya estaba.
 */
class RegistrarEnvioTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;
    use TokensPrueba;

    private string $cobroId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
        // 2026-10-01 10:00:41 en La Paz.
        CarbonImmutable::setTestNow('2026-10-01T14:00:41Z');
        $this->crearContrato();
        $this->cobroId = $this->crearCobro('COB-2026-0001', '1000.00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_crea_el_envio_con_la_hora_actual_y_responde_201(): void
    {
        $this->anotar(['tipo' => 'NOTA_COBRO', 'destinatario' => 'cliente1@correo.test', 'ejecucionN8n' => '1532'])
            ->assertCreated()
            ->assertExactJson([
                'tipo' => 'NOTA_COBRO',
                'destinatario' => 'cliente1@correo.test',
                'enviadoEn' => '2026-10-01T14:00:41Z',
            ]);

        $fila = EnvioCobroModelo::query()->sole();
        $this->assertSame($this->cobroId, $fila->cobro_id);
        $this->assertSame('NOTA_COBRO', $fila->tipo);
        $this->assertSame('1532', $fila->ejecucion_n8n);
        $this->assertTrue($fila->enviado_en->equalTo(CarbonImmutable::parse('2026-10-01T14:00:41Z')));
    }

    public function test_el_envio_aparece_en_el_cobro(): void
    {
        $this->anotar(['tipo' => 'NOTA_COBRO', 'destinatario' => 'cliente1@correo.test'])->assertCreated();

        $this->getJson('/cobros/'.$this->cobroId, ['Authorization' => 'Bearer '.$this->firmarConRoles(['automatizacion', 'gerente'], 'n8n')])
            ->assertOk()
            ->assertJsonPath('envios', [
                ['tipo' => 'NOTA_COBRO', 'destinatario' => 'cliente1@correo.test', 'enviadoEn' => '2026-10-01T14:00:41Z'],
            ]);
    }

    public function test_sin_ejecucion_de_n8n_la_guarda_como_null(): void
    {
        $this->anotar(['tipo' => 'CONSTANCIA_PAGO', 'destinatario' => 'cliente1@correo.test'])->assertCreated();

        $this->assertNull(EnvioCobroModelo::query()->sole()->ejecucion_n8n);
    }

    public function test_si_ya_hay_un_envio_de_ese_tipo_responde_200_con_el_existente_sin_cambiarlo(): void
    {
        $this->anotar(['tipo' => 'NOTA_COBRO', 'destinatario' => 'cliente1@correo.test', 'ejecucionN8n' => '1'])->assertCreated();
        CarbonImmutable::setTestNow('2026-10-01T15:00:00Z');

        $this->anotar(['tipo' => 'NOTA_COBRO', 'destinatario' => 'otro@correo.test', 'ejecucionN8n' => '2'])
            ->assertOk()
            ->assertExactJson([
                'tipo' => 'NOTA_COBRO',
                'destinatario' => 'cliente1@correo.test',
                'enviadoEn' => '2026-10-01T14:00:41Z',
            ]);

        $fila = EnvioCobroModelo::query()->sole();
        $this->assertSame('cliente1@correo.test', $fila->destinatario);
        $this->assertSame('1', $fila->ejecucion_n8n);
    }

    public function test_cada_tipo_se_anota_aparte(): void
    {
        foreach (['NOTA_COBRO', 'RECORDATORIO_POR_VENCER', 'RECORDATORIO_VENCIDO', 'CONSTANCIA_PAGO'] as $tipo) {
            $this->anotar(['tipo' => $tipo, 'destinatario' => 'cliente1@correo.test'])->assertCreated();
        }

        $this->assertSame(4, EnvioCobroModelo::query()->where('cobro_id', $this->cobroId)->count());
    }

    public function test_un_recordatorio_anotado_saca_al_cobro_de_los_recordatorios(): void
    {
        // El cobro vence el 2026-10-31; el 2026-10-29 está por vencer.
        CarbonImmutable::setTestNow('2026-10-29T12:00:00Z');
        $encabezados = ['Authorization' => 'Bearer '.$this->firmarConRoles(['automatizacion', 'gerente'], 'n8n')];
        $this->getJson('/cobros/recordatorios', $encabezados)->assertJsonPath('porVencer.0.id', $this->cobroId);

        $this->anotar(['tipo' => 'RECORDATORIO_POR_VENCER', 'destinatario' => 'cliente1@correo.test'])->assertCreated();

        $this->getJson('/cobros/recordatorios', $encabezados)->assertJsonCount(0, 'porVencer');
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     */
    #[DataProvider('cuerposInvalidos')]
    public function test_datos_invalidos_responden_400(array $cuerpo, string $campo): void
    {
        $this->anotar($cuerpo)
            ->assertStatus(400)
            ->assertJsonPath('codigo', 'DATOS_INVALIDOS')
            ->assertJsonPath('errores.0.campo', $campo);

        $this->assertSame(0, EnvioCobroModelo::query()->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function cuerposInvalidos(): array
    {
        return [
            'sin tipo' => [['destinatario' => 'cliente1@correo.test'], 'tipo'],
            'tipo desconocido' => [['tipo' => 'FACTURA', 'destinatario' => 'cliente1@correo.test'], 'tipo'],
            'tipo en minúsculas' => [['tipo' => 'nota_cobro', 'destinatario' => 'cliente1@correo.test'], 'tipo'],
            'sin destinatario' => [['tipo' => 'NOTA_COBRO'], 'destinatario'],
            'destinatario vacío' => [['tipo' => 'NOTA_COBRO', 'destinatario' => '   '], 'destinatario'],
            'destinatario de 255 caracteres' => [['tipo' => 'NOTA_COBRO', 'destinatario' => str_repeat('a', 243).'@correo.test'], 'destinatario'],
            'ejecución de 65 caracteres' => [['tipo' => 'NOTA_COBRO', 'destinatario' => 'a@b.test', 'ejecucionN8n' => str_repeat('1', 65)], 'ejecucionN8n'],
        ];
    }

    public function test_un_cobro_que_no_existe_responde_404(): void
    {
        $this->anotar(['tipo' => 'NOTA_COBRO', 'destinatario' => 'cliente1@correo.test'], 'c0b00000-0000-4000-8000-0000000000ff')
            ->assertNotFound()
            ->assertJsonPath('codigo', 'COBRO_NO_ENCONTRADO');
        $this->anotar(['tipo' => 'NOTA_COBRO', 'destinatario' => 'cliente1@correo.test'], 'no-es-un-id')
            ->assertNotFound()
            ->assertJsonPath('codigo', 'COBRO_NO_ENCONTRADO');
    }

    /**
     * @param  list<string>  $roles
     */
    #[DataProvider('rolesSinAcceso')]
    public function test_los_roles_distintos_de_automatizacion_reciben_403(array $roles, string $azp): void
    {
        $this->anotar(['tipo' => 'NOTA_COBRO', 'destinatario' => 'cliente1@correo.test'], null, $roles, $azp)
            ->assertForbidden()
            ->assertJsonPath('codigo', 'SIN_PERMISO');

        $this->assertSame(0, EnvioCobroModelo::query()->count());
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function rolesSinAcceso(): array
    {
        return [
            'admin' => [['admin'], 'erp'],
            'gerente' => [['gerente'], 'erp'],
            'automatizacion desde el ERP' => [['automatizacion', 'admin'], 'erp'],
        ];
    }

    public function test_sin_token_responde_401(): void
    {
        $this->postJson('/cobros/'.$this->cobroId.'/envios', ['tipo' => 'NOTA_COBRO', 'destinatario' => 'a@b.test'])
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @param  list<string>  $roles
     */
    private function anotar(array $cuerpo, ?string $id = null, array $roles = ['automatizacion', 'gerente'], string $azp = 'n8n'): TestResponse
    {
        return $this->postJson(
            '/cobros/'.($id ?? $this->cobroId).'/envios',
            $cuerpo,
            ['Authorization' => 'Bearer '.$this->firmarConRoles($roles, $azp)],
        );
    }
}
