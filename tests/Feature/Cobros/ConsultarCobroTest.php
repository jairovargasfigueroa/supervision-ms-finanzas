<?php

namespace Tests\Feature\Cobros;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;
use Tests\Utilidades\TokensPrueba;

/**
 * 008: `GET /cobros/{id}`, mismo objeto que en la lista, solo para `admin` y `gerente`. No choca con
 * `GET /cobros/resumen`.
 */
class ConsultarCobroTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;
    use TokensPrueba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
        $this->crearContrato('100000.00');
    }

    public function test_devuelve_el_cobro_con_el_formato_de_la_lista(): void
    {
        $id = $this->crearCobro('COB-2026-0004', '41881.80', '2026-10-29', self::PLANILLA_ID);

        $this->consultar($id)
            ->assertOk()
            ->assertExactJson([
                'id' => $id,
                'numero' => 'COB-2026-0004',
                'obraId' => self::OBRA_ID,
                'planillaId' => self::PLANILLA_ID,
                'montoNeto' => 41881.8,
                'emitidoEn' => '2026-10-29',
                'venceEn' => '2026-11-28',
                'estado' => 'PENDIENTE',
            ]);
    }

    public function test_el_gerente_tambien_lo_ve(): void
    {
        $id = $this->crearCobro('COB-2026-0001', '1000.00');

        $this->consultar($id, ['gerente'])->assertOk()->assertJsonPath('id', $id);
    }

    public function test_un_id_que_no_existe_responde_404(): void
    {
        $this->consultar('c0b00000-0000-4000-8000-0000000000ff')
            ->assertNotFound()
            ->assertJsonPath('codigo', 'RUTA_NO_ENCONTRADA');
    }

    public function test_un_id_que_no_es_uuid_responde_404(): void
    {
        $this->consultar('no-es-un-id')->assertNotFound();
    }

    public function test_el_resumen_sigue_respondiendo_y_no_se_toma_como_id(): void
    {
        $this->consultar('resumen')
            ->assertOk()
            ->assertJsonStructure(['vencidos' => ['cantidad', 'monto'], 'porVencer30Dias' => ['cantidad', 'monto']]);
    }

    /**
     * @param  list<string>  $roles
     */
    #[DataProvider('rolesSinAcceso')]
    public function test_los_roles_sin_acceso_reciben_403_sin_permiso(array $roles, string $azp): void
    {
        $id = $this->crearCobro('COB-2026-0001', '1000.00');

        $this->consultar($id, $roles, $azp)
            ->assertForbidden()
            ->assertJsonPath('codigo', 'SIN_PERMISO');
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function rolesSinAcceso(): array
    {
        return [
            'residente' => [['residente'], 'erp'],
            'maestro' => [['maestro'], 'app-obra'],
            'almacenero' => [['almacenero'], 'app-obra'],
            'cliente' => [['cliente'], 'portal'],
            'gerente desde el portal' => [['gerente', 'cliente'], 'portal'],
        ];
    }

    public function test_sin_token_responde_401(): void
    {
        $this->getJson('/cobros/c0b00000-0000-4000-8000-000000000001')
            ->assertUnauthorized()
            ->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    /**
     * @param  list<string>  $roles
     */
    private function consultar(string $id, array $roles = ['admin'], string $azp = 'erp'): TestResponse
    {
        return $this->getJson('/cobros/'.$id, ['Authorization' => 'Bearer '.$this->firmarConRoles($roles, $azp)]);
    }
}
