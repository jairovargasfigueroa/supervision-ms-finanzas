<?php

namespace Tests\Feature\Contratos;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Contratos\Infraestructura\Persistencia\ContratoModelo;
use App\Contratos\Infraestructura\Persistencia\ReservaSaldoModelo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Utilidades\TokensPrueba;

/**
 * 006: `GET /contratos?obraId=` con saldo disponible, total cobrado y total reservado. Las reservas y los cobros los
 * crea la 008; aquí se insertan directo en sus tablas.
 */
class ConsultarContratosTest extends TestCase
{
    use RefreshDatabase;
    use TokensPrueba;

    private const OBRA_ID = '0b1a0000-0000-4000-8000-000000000001';

    private const OTRA_OBRA_ID = '0b1a0000-0000-4000-8000-000000000002';

    private const CONTRATO_ID = 'c0a10000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
        ContratoModelo::query()->create([
            'id' => self::CONTRATO_ID,
            'obra_id' => self::OBRA_ID,
            'monto' => '1250000.00',
            'plazo_dias' => 300,
            'anticipo_pct' => '10.00',
            'retencion_pct' => '7.00',
            'firmado_en' => '2026-10-20',
        ]);
    }

    public function test_un_contrato_sin_cobros_ni_reservas_tiene_el_saldo_igual_al_monto(): void
    {
        $this->consultar(self::OBRA_ID)
            ->assertOk()
            ->assertExactJson([[
                'id' => self::CONTRATO_ID,
                'obraId' => self::OBRA_ID,
                'monto' => 1250000.0,
                'plazoDias' => 300,
                'anticipoPct' => 10.0,
                'retencionPct' => 7.0,
                'firmadoEn' => '2026-10-20',
                'saldoDisponible' => 1250000.0,
                'totalCobrado' => 0.0,
                'totalReservado' => 0.0,
            ]]);
    }

    public function test_descuenta_los_cobros_y_solo_las_reservas_vigentes(): void
    {
        $this->cobro('41881.80', 'PENDIENTE');
        $this->cobro('10000.10', 'VENCIDO');
        $this->reserva('20000.05', 'RESERVADA');
        $this->reserva('0.20', 'RESERVADA');
        // No cuentan: la liberada (saga compensada) y la consumida (ya cuenta su cobro).
        $this->reserva('99999.00', 'LIBERADA');
        $this->reserva('41881.80', 'CONSUMIDA');

        $this->consultar(self::OBRA_ID)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.totalCobrado', 51881.9)
            ->assertJsonPath('0.totalReservado', 20000.25)
            ->assertJsonPath('0.saldoDisponible', 1178117.85)
            ->assertJsonPath('0.monto', 1250000);
    }

    public function test_los_cobros_y_reservas_de_otro_contrato_no_cuentan(): void
    {
        $otro = (string) Str::uuid();
        ContratoModelo::query()->create([
            'id' => $otro,
            'obra_id' => self::OTRA_OBRA_ID,
            'monto' => '500000.00',
            'plazo_dias' => 100,
            'anticipo_pct' => '0.00',
            'retencion_pct' => '0.00',
            'firmado_en' => '2026-10-01',
        ]);
        $this->cobro('1000.00', 'PENDIENTE', $otro);
        $this->reserva('2000.00', 'RESERVADA', $otro);

        $this->consultar(self::OBRA_ID)->assertOk()->assertJsonPath('0.saldoDisponible', 1250000);
        $this->consultar(self::OTRA_OBRA_ID)->assertOk()
            ->assertJsonPath('0.id', $otro)
            ->assertJsonPath('0.saldoDisponible', 497000);
    }

    public function test_una_obra_sin_contrato_devuelve_una_lista_vacia(): void
    {
        $this->consultar((string) Str::uuid())->assertOk()->assertExactJson([]);
    }

    public function test_sin_obra_id_o_con_uno_que_no_es_uuid_responde_400_datos_invalidos(): void
    {
        foreach (['/contratos', '/contratos?obraId=abc'] as $ruta) {
            $this->getJson($ruta, $this->encabezados(['admin']))
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('codigo', 'DATOS_INVALIDOS')
                ->assertJsonPath('errores.0.campo', 'obraId');
        }
    }

    public function test_gerente_tambien_puede_consultar(): void
    {
        $this->consultar(self::OBRA_ID, ['gerente'])->assertOk()->assertJsonCount(1);
    }

    /**
     * @param  list<string>  $roles
     */
    #[DataProvider('rolesSinAcceso')]
    public function test_los_roles_sin_acceso_reciben_403_sin_permiso(array $roles, string $azp): void
    {
        $this->consultar(self::OBRA_ID, $roles, $azp)
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
            'admin desde el portal' => [['admin', 'cliente'], 'portal'],
        ];
    }

    public function test_sin_token_responde_401(): void
    {
        $this->getJson('/contratos?obraId='.self::OBRA_ID)->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    /**
     * @param  list<string>  $roles
     */
    private function consultar(string $obraId, array $roles = ['admin'], string $azp = 'erp'): TestResponse
    {
        return $this->getJson('/contratos?obraId='.$obraId, $this->encabezados($roles, $azp));
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, string>
     */
    private function encabezados(array $roles, string $azp = 'erp'): array
    {
        return ['Authorization' => 'Bearer '.$this->firmarConRoles($roles, $azp)];
    }

    private function cobro(string $monto, string $estado, string $contratoId = self::CONTRATO_ID): void
    {
        CobroModelo::query()->create([
            'id' => (string) Str::uuid(),
            'contrato_id' => $contratoId,
            'planilla_id' => (string) Str::uuid(),
            'numero' => 'COB-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'monto_neto' => $monto,
            'emitido_en' => '2026-11-02',
            'vence_en' => '2026-12-02',
            'estado' => $estado,
        ]);
    }

    private function reserva(string $monto, string $estado, string $contratoId = self::CONTRATO_ID): void
    {
        ReservaSaldoModelo::query()->create([
            'id' => (string) Str::uuid(),
            'contrato_id' => $contratoId,
            'planilla_id' => (string) Str::uuid(),
            'monto' => $monto,
            'estado' => $estado,
        ]);
    }
}
