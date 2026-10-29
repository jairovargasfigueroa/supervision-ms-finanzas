<?php

namespace Tests\Feature\Contratos;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Contratos\Infraestructura\Persistencia\ContratoModelo;
use App\Contratos\Infraestructura\Persistencia\ReservaSaldoModelo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 008: restricciones de las tablas que escribe la saga. Una sola reserva vigente por planilla, un cobro por planilla
 * y número de cobro único; el contrato ya no guarda el saldo (se calcula al leer).
 */
class RestriccionesSagaTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRATO_ID = 'c0a10000-0000-4000-8000-000000000001';

    private const PLANILLA_ID = '9a1a0000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        ContratoModelo::query()->create([
            'id' => self::CONTRATO_ID,
            'obra_id' => '0b1a0000-0000-4000-8000-000000000001',
            'monto' => '100000.00',
            'plazo_dias' => 300,
            'anticipo_pct' => '10.00',
            'retencion_pct' => '7.00',
            'firmado_en' => '2026-10-20',
        ]);
    }

    public function test_el_contrato_ya_no_tiene_la_columna_del_saldo(): void
    {
        $this->assertFalse(Schema::hasColumn('contrato', 'saldo_disponible'));
    }

    public function test_no_admite_dos_reservas_vigentes_para_la_misma_planilla(): void
    {
        $this->reserva('RESERVADA');

        $this->expectException(QueryException::class);
        $this->reserva('RESERVADA');
    }

    public function test_admite_reservas_liberadas_o_consumidas_junto_a_una_vigente(): void
    {
        $this->reserva('LIBERADA');
        $this->reserva('LIBERADA');
        $this->reserva('CONSUMIDA');
        $this->reserva('RESERVADA');

        $this->assertSame(4, ReservaSaldoModelo::query()->where('planilla_id', self::PLANILLA_ID)->count());
    }

    public function test_no_admite_dos_cobros_con_el_mismo_numero(): void
    {
        $this->cobro('COB-2026-0001', self::PLANILLA_ID);

        $this->expectException(QueryException::class);
        $this->cobro('COB-2026-0001', (string) Str::uuid());
    }

    public function test_no_admite_dos_cobros_para_la_misma_planilla(): void
    {
        $this->cobro('COB-2026-0001', self::PLANILLA_ID);

        $this->expectException(QueryException::class);
        $this->cobro('COB-2026-0002', self::PLANILLA_ID);
    }

    private function reserva(string $estado): void
    {
        ReservaSaldoModelo::query()->create([
            'id' => (string) Str::uuid(),
            'contrato_id' => self::CONTRATO_ID,
            'planilla_id' => self::PLANILLA_ID,
            'monto' => '1000.00',
            'estado' => $estado,
        ]);
    }

    private function cobro(string $numero, string $planillaId): void
    {
        CobroModelo::query()->create([
            'id' => (string) Str::uuid(),
            'contrato_id' => self::CONTRATO_ID,
            'planilla_id' => $planillaId,
            'numero' => $numero,
            'monto_neto' => '1000.00',
            'emitido_en' => '2026-10-29',
            'vence_en' => '2026-11-28',
            'estado' => 'PENDIENTE',
        ]);
    }
}
