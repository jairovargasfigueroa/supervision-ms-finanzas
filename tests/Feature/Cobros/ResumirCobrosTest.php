<?php

namespace Tests\Feature\Cobros;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Contratos\Infraestructura\Persistencia\ContratoModelo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Utilidades\TokensPrueba;

/**
 * 009: `GET /cobros/resumen`. Vencidos = `VENCIDO` o `PENDIENTE` con vencimiento anterior a hoy en La Paz; por vencer
 * en 30 días = `PENDIENTE` con vencimiento entre hoy y hoy + 30, ambos incluidos. Los cobros los emite la 008; aquí se
 * insertan directo en la tabla.
 */
class ResumirCobrosTest extends TestCase
{
    use RefreshDatabase;
    use TokensPrueba;

    private const CONTRATO_ID = 'c0a10000-0000-4000-8000-000000000001';

    private const OTRO_CONTRATO_ID = 'c0a10000-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
        $obra = 1;
        foreach ([self::CONTRATO_ID, self::OTRO_CONTRATO_ID] as $id) {
            ContratoModelo::query()->create([
                'id' => $id,
                'obra_id' => '0b1a0000-0000-4000-8000-00000000000'.$obra++,
                'monto' => '3000000.00',
                'plazo_dias' => 300,
                'anticipo_pct' => '10.00',
                'retencion_pct' => '7.00',
                'firmado_en' => '2026-06-01',
            ]);
        }
        // 02:00 UTC del 30 de octubre: en La Paz todavía es el 29 (22:00). Hoy = 2026-10-29; hoy + 30 = 2026-11-28.
        CarbonImmutable::setTestNow('2026-10-30T02:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_sin_cobros_todo_en_cero(): void
    {
        $this->resumen()
            ->assertOk()
            ->assertExactJson([
                'vencidos' => ['cantidad' => 0, 'monto' => 0],
                'porVencer30Dias' => ['cantidad' => 0, 'monto' => 0],
            ]);
    }

    public function test_con_los_cobros_de_los_datos_de_prueba(): void
    {
        // docs/datos-prueba.md, sección 8, con D = hoy: vence D − 10 (VENCIDO), D + 10 y D + 18 (PENDIENTE).
        $this->cobro('2026-10-19', 'VENCIDO', '290134.80');
        $this->cobro('2026-11-08', 'PENDIENTE', '405865.20', self::OTRO_CONTRATO_ID);
        $this->cobro('2026-11-16', 'PENDIENTE', '365764.40');

        $this->resumen()
            ->assertOk()
            ->assertExactJson([
                'vencidos' => ['cantidad' => 1, 'monto' => 290134.8],
                'porVencer30Dias' => ['cantidad' => 2, 'monto' => 771629.6],
            ]);
    }

    public function test_cuenta_como_vencido_el_pendiente_cuyo_vencimiento_ya_paso_en_la_paz(): void
    {
        $this->cobro('2026-10-01', 'VENCIDO', '1000.10');
        // Venció ayer y todavía no se marcó VENCIDO.
        $this->cobro('2026-10-28', 'PENDIENTE', '2000.25', self::OTRO_CONTRATO_ID);
        // Vence hoy en La Paz (en UTC ya es mañana): todavía no está vencido, está por vencer.
        $this->cobro('2026-10-29', 'PENDIENTE', '300.00');

        $this->resumen()
            ->assertOk()
            ->assertJsonPath('vencidos.cantidad', 2)
            ->assertJsonPath('vencidos.monto', 3000.35)
            ->assertJsonPath('porVencer30Dias.cantidad', 1)
            ->assertJsonPath('porVencer30Dias.monto', 300);
    }

    public function test_por_vencer_incluye_hoy_y_hoy_mas_30_y_no_el_dia_siguiente(): void
    {
        $this->cobro('2026-10-29', 'PENDIENTE', '100.00');
        $this->cobro('2026-11-28', 'PENDIENTE', '200.00');
        $this->cobro('2026-11-29', 'PENDIENTE', '400.00');
        $this->cobro('2027-03-01', 'PENDIENTE', '800.00');

        $this->resumen()
            ->assertOk()
            ->assertExactJson([
                'vencidos' => ['cantidad' => 0, 'monto' => 0],
                'porVencer30Dias' => ['cantidad' => 2, 'monto' => 300],
            ]);
    }

    public function test_un_cobro_vencido_no_cuenta_como_por_vencer_aunque_su_fecha_caiga_en_los_30_dias(): void
    {
        // Estado guardado VENCIDO con una fecha que no lo justifica: manda el estado, no se cuenta dos veces.
        $this->cobro('2026-11-10', 'VENCIDO', '500.00');

        $this->resumen()
            ->assertOk()
            ->assertExactJson([
                'vencidos' => ['cantidad' => 1, 'monto' => 500],
                'porVencer30Dias' => ['cantidad' => 0, 'monto' => 0],
            ]);
    }

    public function test_el_resumen_cambia_con_el_dia(): void
    {
        $this->cobro('2026-10-29', 'PENDIENTE', '100.00');

        $this->resumen()->assertJsonPath('vencidos.cantidad', 0)->assertJsonPath('porVencer30Dias.cantidad', 1);

        // Al día siguiente en La Paz (04:00 UTC del 30 = 00:00 en La Paz) ya está vencido.
        CarbonImmutable::setTestNow('2026-10-30T04:00:00Z');
        $this->resumen()->assertJsonPath('vencidos.cantidad', 1)->assertJsonPath('porVencer30Dias.cantidad', 0);
    }

    public function test_gerente_tambien_puede_verlo(): void
    {
        $this->resumen(['gerente'])->assertOk()->assertJsonStructure([
            'vencidos' => ['cantidad', 'monto'],
            'porVencer30Dias' => ['cantidad', 'monto'],
        ]);
    }

    /**
     * @param  list<string>  $roles
     */
    #[DataProvider('rolesSinAcceso')]
    public function test_los_roles_sin_acceso_reciben_403_sin_permiso(array $roles, string $azp): void
    {
        $this->resumen($roles, $azp)
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
        $this->getJson('/cobros/resumen')->assertUnauthorized()->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    /**
     * @param  list<string>  $roles
     */
    private function resumen(array $roles = ['admin'], string $azp = 'erp'): TestResponse
    {
        return $this->getJson('/cobros/resumen', ['Authorization' => 'Bearer '.$this->firmarConRoles($roles, $azp)]);
    }

    private function cobro(string $venceEn, string $estado, string $monto, string $contratoId = self::CONTRATO_ID): void
    {
        CobroModelo::query()->create([
            'id' => (string) Str::uuid(),
            'contrato_id' => $contratoId,
            'planilla_id' => (string) Str::uuid(),
            'numero' => 'COB-2026-'.Str::random(4),
            'monto_neto' => $monto,
            'emitido_en' => '2026-09-01',
            'vence_en' => $venceEn,
            'estado' => $estado,
        ]);
    }
}
