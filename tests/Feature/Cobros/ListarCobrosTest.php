<?php

namespace Tests\Feature\Cobros;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Contratos\Infraestructura\Persistencia\ContratoModelo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Utilidades\TokensPrueba;

/**
 * 006: `GET /cobros?obraId=&estado=` paginado (por defecto `venceEn asc`). Los cobros los emite la 008; aquí se
 * insertan directo en la tabla.
 */
class ListarCobrosTest extends TestCase
{
    use RefreshDatabase;
    use TokensPrueba;

    private const OBRA_ID = '0b1a0000-0000-4000-8000-000000000001';

    private const OTRA_OBRA_ID = '0b1a0000-0000-4000-8000-000000000002';

    private const CONTRATO_ID = 'c0a10000-0000-4000-8000-000000000001';

    private const OTRO_CONTRATO_ID = 'c0a10000-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
        foreach ([self::CONTRATO_ID => self::OBRA_ID, self::OTRO_CONTRATO_ID => self::OTRA_OBRA_ID] as $id => $obraId) {
            ContratoModelo::query()->create([
                'id' => $id,
                'obra_id' => $obraId,
                'monto' => '1250000.00',
                'plazo_dias' => 300,
                'anticipo_pct' => '10.00',
                'retencion_pct' => '7.00',
                'firmado_en' => '2026-10-20',
            ]);
        }
    }

    public function test_sin_cobros_devuelve_una_pagina_vacia(): void
    {
        $this->listar('?obraId='.self::OBRA_ID)
            ->assertOk()
            ->assertExactJson(['items' => [], 'total' => 0, 'pagina' => 1, 'tamano' => 20]);
    }

    public function test_lista_los_cobros_de_la_obra_por_vencimiento_con_el_formato_del_contrato(): void
    {
        $tarde = $this->cobro('COB-2026-0002', '2027-01-15', 'PENDIENTE');
        $temprano = $this->cobro('COB-2026-0001', '2026-12-02', 'VENCIDO', monto: '41881.80');
        $this->cobro('COB-2026-0003', '2026-11-01', 'PENDIENTE', self::OTRO_CONTRATO_ID);

        $this->listar('?obraId='.self::OBRA_ID)
            ->assertOk()
            ->assertExactJson([
                'items' => [
                    [
                        'id' => $temprano['id'],
                        'numero' => 'COB-2026-0001',
                        'obraId' => self::OBRA_ID,
                        'planillaId' => $temprano['planilla_id'],
                        'montoNeto' => 41881.8,
                        'emitidoEn' => '2026-11-02',
                        'venceEn' => '2026-12-02',
                        'estado' => 'VENCIDO',
                    ],
                    [
                        'id' => $tarde['id'],
                        'numero' => 'COB-2026-0002',
                        'obraId' => self::OBRA_ID,
                        'planillaId' => $tarde['planilla_id'],
                        'montoNeto' => 1000.0,
                        'emitidoEn' => '2026-11-02',
                        'venceEn' => '2027-01-15',
                        'estado' => 'PENDIENTE',
                    ],
                ],
                'total' => 2,
                'pagina' => 1,
                'tamano' => 20,
            ]);
    }

    public function test_filtra_por_estado_y_sin_obra_lista_todas(): void
    {
        $this->cobro('COB-2026-0001', '2026-12-02', 'VENCIDO');
        $this->cobro('COB-2026-0002', '2027-01-15', 'PENDIENTE');
        $this->cobro('COB-2026-0003', '2026-11-01', 'PENDIENTE', self::OTRO_CONTRATO_ID);

        $pendientes = $this->listar('?obraId='.self::OBRA_ID.'&estado=PENDIENTE')->assertOk();
        $pendientes->assertJsonPath('total', 1)->assertJsonPath('items.0.numero', 'COB-2026-0002');

        $vencidos = $this->listar('?estado=VENCIDO')->assertOk();
        $vencidos->assertJsonPath('total', 1)->assertJsonPath('items.0.numero', 'COB-2026-0001');

        $todos = $this->listar()->assertOk()->assertJsonPath('total', 3);
        $this->assertSame(['COB-2026-0003', 'COB-2026-0001', 'COB-2026-0002'], array_column($todos->json('items'), 'numero'));
    }

    public function test_pagina_y_ordena(): void
    {
        $this->cobro('COB-2026-0001', '2026-12-02', 'PENDIENTE');
        $this->cobro('COB-2026-0002', '2027-01-15', 'PENDIENTE');
        $this->cobro('COB-2026-0003', '2027-02-15', 'PENDIENTE');

        $this->listar('?obraId='.self::OBRA_ID.'&tamano=2&pagina=2')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('pagina', 2)
            ->assertJsonPath('tamano', 2)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.numero', 'COB-2026-0003');

        $desc = $this->listar('?obraId='.self::OBRA_ID.'&orden=venceEn&direccion=desc')->assertOk();
        $this->assertSame(['COB-2026-0003', 'COB-2026-0002', 'COB-2026-0001'], array_column($desc->json('items'), 'numero'));

        $this->listar('?orden=emitidoEn')->assertOk()->assertJsonPath('total', 3);
    }

    public function test_parametros_invalidos_responden_400_y_tamano_mayor_a_100_paginacion_invalida(): void
    {
        $casos = [
            '?obraId=abc' => 'obraId',
            '?estado=PAGADO' => 'estado',
            '?pagina=0' => 'pagina',
            '?tamano=x' => 'tamano',
            '?orden=montoNeto' => 'orden',
            '?direccion=arriba' => 'direccion',
        ];
        foreach ($casos as $consulta => $campo) {
            $this->listar($consulta)
                ->assertStatus(400)
                ->assertJsonPath('codigo', 'DATOS_INVALIDOS')
                ->assertJsonPath('errores.0.campo', $campo);
        }

        $this->listar('?tamano=101')
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('codigo', 'PAGINACION_INVALIDA')
            ->assertJsonPath('title', 'Datos inválidos');
        $this->listar('?tamano=100')->assertOk();
    }

    public function test_gerente_tambien_puede_listar(): void
    {
        $this->listar('', ['gerente'])->assertOk();
    }

    /**
     * @param  list<string>  $roles
     */
    #[DataProvider('rolesSinAcceso')]
    public function test_los_roles_sin_acceso_reciben_403_sin_permiso(array $roles, string $azp): void
    {
        $this->listar('?obraId='.self::OBRA_ID, $roles, $azp)
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
        $this->getJson('/cobros')->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    /**
     * @param  list<string>  $roles
     */
    private function listar(string $consulta = '', array $roles = ['admin'], string $azp = 'erp'): TestResponse
    {
        return $this->getJson('/cobros'.$consulta, ['Authorization' => 'Bearer '.$this->firmarConRoles($roles, $azp)]);
    }

    /**
     * @return array{id: string, planilla_id: string}
     */
    private function cobro(string $numero, string $venceEn, string $estado, string $contratoId = self::CONTRATO_ID, string $monto = '1000.00'): array
    {
        $fila = [
            'id' => (string) Str::uuid(),
            'contrato_id' => $contratoId,
            'planilla_id' => (string) Str::uuid(),
            'numero' => $numero,
            'monto_neto' => $monto,
            'emitido_en' => '2026-11-02',
            'vence_en' => $venceEn,
            'estado' => $estado,
        ];
        CobroModelo::query()->create($fila);

        return ['id' => $fila['id'], 'planilla_id' => $fila['planilla_id']];
    }
}
