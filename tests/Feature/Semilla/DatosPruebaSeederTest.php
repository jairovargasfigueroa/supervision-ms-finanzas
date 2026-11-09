<?php

namespace Tests\Feature\Semilla;

use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Compartido\Infraestructura\Semilla\GuardaDatosPrueba;
use Carbon\CarbonImmutable;
use Database\Seeders\DatosPruebaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Tests\Utilidades\TokensPrueba;

/**
 * 011: datos de prueba de Finanzas (docs/datos-prueba.md sección 8). Con D fija: contratos con sus saldos (leídos de
 * `GET /contratos`, porque no se guardan), reservas `CONSUMIDA`, cobros con número por año y estado según D, el
 * resumen del tablero, idempotencia, sin eventos y la guarda. Desde la 201, el envío `NOTA_COBRO` de cada cobro.
 */
class DatosPruebaSeederTest extends TestCase
{
    use RefreshDatabase;
    use TokensPrueba;

    private const OBRA_1 = 'b0000000-0000-4000-8000-000000000001';

    private const OBRA_2 = 'b0000000-0000-4000-8000-000000000002';

    private const OBRA_3 = 'b0000000-0000-4000-8000-000000000003';

    private const COBRO_101 = 'c2000000-0000-4000-8000-000000000101';

    private const COBRO_201 = 'c2000000-0000-4000-8000-000000000201';

    private const COBRO_102 = 'c2000000-0000-4000-8000-000000000102';

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_carga_3_contratos_3_reservas_3_cobros_y_3_envios_y_la_segunda_carga_no_duplica(): void
    {
        $this->cargar('2026-10-28');
        $primera = $this->foto();
        $this->cargar('2026-10-28');

        $this->assertSame(3, DB::table('contrato')->count());
        $this->assertSame(3, DB::table('reserva_saldo')->count());
        $this->assertSame(3, DB::table('cobro')->count());
        $this->assertSame(3, DB::table('envio_cobro')->count());
        $this->assertSame($primera, $this->foto());
    }

    public function test_603_con_historia_suma_las_obras_4_a_6_con_pagos_y_la_segunda_carga_no_duplica(): void
    {
        config(['semilla.historia' => true]);
        $this->cargar('2026-11-09');
        $this->cargar('2026-11-09');

        $this->assertSame(6, DB::table('contrato')->count());
        $this->assertSame(20, DB::table('reserva_saldo')->count());
        $this->assertSame(20, DB::table('cobro')->count());
        $this->assertSame(39, DB::table('envio_cobro')->count());
        // Pagados con fecha, comprobante y quién lo registró (admin1); el vencido sin pagar de la obra 5
        $pagados = DB::table('cobro')->where('estado', 'PAGADO')->get();
        $this->assertCount(13, $pagados);
        foreach ($pagados as $cobro) {
            $this->assertNotNull($cobro->pagado_en);
            $this->assertStringStartsWith('Transferencia BNB ', (string) $cobro->comprobante);
            $this->assertSame(DatosPruebaSeeder::PAGOS_REGISTRADOS_POR, $cobro->pago_registrado_por);
            $this->assertNotNull($cobro->pago_registrado_en);
        }
        $this->assertSame('VENCIDO', DB::table('cobro')->where('id', 'c2000000-0000-4000-8000-000000000504')->value('estado'));
        $this->assertSame('PENDIENTE', DB::table('cobro')->where('id', 'c2000000-0000-4000-8000-000000000506')->value('estado'));
        // Números correlativos por año en orden de emisión, sin repetir, sobre la 011 y la historia juntas
        $numeros = DB::table('cobro')->pluck('numero')->sort()->values()->all();
        $this->assertSame(array_map(fn (int $n): string => sprintf('COB-2026-%04d', $n), range(1, 20)), $numeros);
    }

    public function test_la_carga_muestra_el_resumen_con_la_fecha_d(): void
    {
        config(['semilla.fecha' => '2026-10-28']);

        $this->artisan('db:seed', ['--class' => DatosPruebaSeeder::class, '--force' => true])
            ->expectsOutputToContain('Finanzas · datos de prueba con D = 2026-10-28: 3 contratos, 3 reservas, 3 cobros, 3 envíos.')
            ->assertSuccessful();
    }

    public function test_contratos_con_los_saldos_de_la_seccion_8(): void
    {
        $this->cargar('2026-10-28');

        $this->contratoDe(self::OBRA_1)->assertOk()->assertExactJson([[
            'id' => 'c0000000-0000-4000-8000-000000000001',
            'obraId' => self::OBRA_1,
            'monto' => 2773800.0,
            'plazoDias' => 360,
            'anticipoPct' => 10.0,
            'retencionPct' => 7.0,
            'firmadoEn' => '2026-06-20',
            'saldoDisponible' => 2117900.8,
            'totalCobrado' => 655899.2,
            'totalReservado' => 0.0,
        ]]);
        $this->contratoDe(self::OBRA_2)->assertOk()
            ->assertJsonPath('0.monto', 3185140)
            ->assertJsonPath('0.plazoDias', 300)
            ->assertJsonPath('0.anticipoPct', 15)
            ->assertJsonPath('0.retencionPct', 7)
            ->assertJsonPath('0.firmadoEn', '2026-05-21')
            ->assertJsonPath('0.totalCobrado', 405865.2)
            ->assertJsonPath('0.totalReservado', 0)
            ->assertJsonPath('0.saldoDisponible', 2779274.8);
        $this->contratoDe(self::OBRA_3)->assertOk()
            ->assertJsonPath('0.monto', 1900140)
            ->assertJsonPath('0.plazoDias', 240)
            ->assertJsonPath('0.anticipoPct', 10)
            ->assertJsonPath('0.retencionPct', 5)
            ->assertJsonPath('0.firmadoEn', '2026-10-16')
            ->assertJsonPath('0.totalCobrado', 0)
            ->assertJsonPath('0.saldoDisponible', 1900140);
    }

    public function test_las_reservas_son_las_de_las_planillas_aprobadas_y_estan_consumidas(): void
    {
        $this->cargar('2026-10-28');

        $this->assertSame(
            [
                ['c1000000-0000-4000-8000-000000000101', 'd0000000-0000-4000-8000-000000000101', 290134.8, 'CONSUMIDA'],
                ['c1000000-0000-4000-8000-000000000102', 'd0000000-0000-4000-8000-000000000102', 365764.4, 'CONSUMIDA'],
                ['c1000000-0000-4000-8000-000000000201', 'd0000000-0000-4000-8000-000000000201', 405865.2, 'CONSUMIDA'],
            ],
            DB::table('reserva_saldo')->orderBy('id')->get()
                ->map(fn (object $r): array => [$r->id, $r->planilla_id, (float) $r->monto, $r->estado])
                ->all(),
        );
    }

    public function test_cobros_con_numero_vencimiento_y_estado_segun_d(): void
    {
        $this->cargar('2026-10-28');

        $this->assertSame([
            self::COBRO_101 => ['COB-2026-0001', 290134.8, '2026-09-18', '2026-10-18', 'VENCIDO'],
            self::COBRO_201 => ['COB-2026-0002', 405865.2, '2026-10-08', '2026-11-07', 'PENDIENTE'],
            self::COBRO_102 => ['COB-2026-0003', 365764.4, '2026-10-16', '2026-11-15', 'PENDIENTE'],
        ], $this->cobros());
    }

    public function test_el_correlativo_empieza_de_nuevo_en_el_anio_nuevo(): void
    {
        // D − 40 = 2026-12-11, D − 20 = 2026-12-31 y D − 12 = 2027-01-08.
        $this->cargar('2027-01-20');

        $this->assertSame([
            self::COBRO_101 => ['COB-2026-0001', 290134.8, '2026-12-11', '2027-01-10', 'VENCIDO'],
            self::COBRO_201 => ['COB-2026-0002', 405865.2, '2026-12-31', '2027-01-30', 'PENDIENTE'],
            self::COBRO_102 => ['COB-2027-0001', 365764.4, '2027-01-08', '2027-02-07', 'PENDIENTE'],
        ], $this->cobros());
    }

    public function test_el_siguiente_cobro_de_la_saga_continua_la_serie(): void
    {
        $this->cargar('2026-10-28');

        $this->assertSame(4, app(CobroRepositorio::class)->siguienteCorrelativo(2026));
    }

    public function test_cobros_de_la_obra_1_uno_vencido_y_uno_pendiente(): void
    {
        $this->cargar('2026-10-28');

        $this->getJson('/cobros?obraId='.self::OBRA_1, $this->encabezados())
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.numero', 'COB-2026-0001')
            ->assertJsonPath('items.0.estado', 'VENCIDO')
            ->assertJsonPath('items.1.numero', 'COB-2026-0003')
            ->assertJsonPath('items.1.estado', 'PENDIENTE');
    }

    public function test_resumen_del_tablero_el_dia_d(): void
    {
        $this->cargar('2026-10-28');
        // 12:00 en La Paz del día D.
        CarbonImmutable::setTestNow('2026-10-28T16:00:00Z');

        $this->getJson('/cobros/resumen', $this->encabezados())
            ->assertOk()
            ->assertExactJson([
                'vencidos' => ['cantidad' => 1, 'monto' => 290134.8],
                'porVencer30Dias' => ['cantidad' => 2, 'monto' => 771629.6],
            ]);
    }

    public function test_sin_semilla_fecha_d_es_hoy_en_la_paz(): void
    {
        // 02:00 UTC del 29: en La Paz todavía es el 28.
        CarbonImmutable::setTestNow('2026-10-29T02:00:00Z');

        $this->cargar(null);

        $this->assertSame('2026-06-20', DB::table('contrato')->where('obra_id', self::OBRA_1)->value('firmado_en'));
        $this->assertSame('COB-2026-0001', DB::table('cobro')->where('id', self::COBRO_101)->value('numero'));
    }

    public function test_no_escribe_eventos_ni_mensajes_procesados(): void
    {
        $this->cargar('2026-10-28');

        $this->assertSame(0, DB::table('outbox_evento')->count());
        $this->assertSame(0, DB::table('mensaje_procesado')->count());
    }

    public function test_cargar_de_nuevo_no_pisa_lo_que_cambio_en_una_demostracion(): void
    {
        $this->cargar('2026-10-28');
        DB::table('cobro')->where('id', self::COBRO_201)->update(['estado' => 'VENCIDO']);

        // Otra D: lo que ya existe se queda como está.
        $this->cargar('2026-11-20');

        $this->assertSame('VENCIDO', DB::table('cobro')->where('id', self::COBRO_201)->value('estado'));
        $this->assertSame('2026-06-20', DB::table('contrato')->where('obra_id', self::OBRA_1)->value('firmado_en'));
    }

    public function test_cada_cobro_tiene_su_nota_de_cobro_enviada_a_las_10_05_de_la_paz_del_dia_de_emision(): void
    {
        $this->cargar('2026-10-28');

        $this->assertSame([
            'c3000000-0000-4000-8000-000000000101' => [self::COBRO_101, 'NOTA_COBRO', 'cliente1@correo.test', null],
            'c3000000-0000-4000-8000-000000000102' => [self::COBRO_102, 'NOTA_COBRO', 'cliente1@correo.test', null],
            'c3000000-0000-4000-8000-000000000201' => [self::COBRO_201, 'NOTA_COBRO', 'demo1@constructora-andina.test', null],
        ], DB::table('envio_cobro')->orderBy('id')->get()
            ->mapWithKeys(fn (object $e): array => [$e->id => [$e->cobro_id, $e->tipo, $e->destinatario, $e->ejecucion_n8n]])
            ->all());

        // D − 40, D − 20 y D − 12 a las 10:05 de La Paz (14:05 UTC).
        foreach ([
            self::COBRO_101 => '2026-09-18T14:05:00Z',
            self::COBRO_201 => '2026-10-08T14:05:00Z',
            self::COBRO_102 => '2026-10-16T14:05:00Z',
        ] as $cobro => $enviadoEn) {
            $this->getJson('/cobros/'.$cobro, $this->encabezados())
                ->assertOk()
                ->assertJsonPath('envios', [['tipo' => 'NOTA_COBRO', 'destinatario' => DB::table('envio_cobro')->where('cobro_id', $cobro)->value('destinatario'), 'enviadoEn' => $enviadoEn]])
                ->assertJsonPath('pagadoEn', null)
                ->assertJsonPath('comprobante', null);
        }
    }

    public function test_el_dia_d_solo_el_cobro_vencido_necesita_recordatorio(): void
    {
        $this->cargar('2026-10-28');
        // 08:00 en La Paz del día D, la hora de «Recordar cobros».
        CarbonImmutable::setTestNow('2026-10-28T12:00:00Z');

        $this->getJson('/cobros/recordatorios', ['Authorization' => 'Bearer '.$this->firmarConRoles(['automatizacion', 'gerente'], 'n8n')])
            ->assertOk()
            ->assertJsonPath('porVencer', [])
            ->assertJsonCount(1, 'vencidos')
            ->assertJsonPath('vencidos.0.numero', 'COB-2026-0001')
            ->assertJsonPath('vencidos.0.diasVencido', 10);
    }

    public function test_falla_si_un_envio_choca_con_otro_del_mismo_tipo_que_ya_existe(): void
    {
        $this->cargar('2026-10-28');
        // Otro envío (otro id) de la nota del cobro 101: (`cobro_id`, `tipo`) es único.
        DB::table('envio_cobro')->where('id', 'c3000000-0000-4000-8000-000000000101')->update(['id' => 'c3000000-0000-4000-8000-000000000999']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('en «envio_cobro» no se pudieron cargar 1 filas');
        $this->cargar('2026-10-28');
    }

    public function test_falla_y_no_carga_nada_si_un_dato_choca_con_otro_que_ya_existe(): void
    {
        // Otro contrato (otro id) para la obra 1: `obra_id` es único.
        DB::table('contrato')->insert([
            'id' => 'c0a10000-0000-4000-8000-000000000099',
            'obra_id' => self::OBRA_1,
            'monto' => '1000.00',
            'plazo_dias' => 100,
            'anticipo_pct' => '10.00',
            'retencion_pct' => '7.00',
            'firmado_en' => '2026-01-01',
        ]);

        try {
            $this->cargar('2026-10-28');
            $this->fail('La carga debía fallar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('chocan con datos que ya existen', $e->getMessage());
        }

        $this->assertSame(1, DB::table('contrato')->count());
        $this->assertSame(0, DB::table('reserva_saldo')->count());
        $this->assertSame(0, DB::table('cobro')->count());
        $this->assertSame(0, DB::table('envio_cobro')->count());
    }

    public function test_se_niega_en_produccion(): void
    {
        config(['app.env' => 'production']);

        // En la consola la excepción termina el comando con código distinto de 0; en la prueba llega tal cual.
        try {
            $this->artisan('db:seed', ['--class' => DatosPruebaSeeder::class, '--force' => true])->run();
            $this->fail('La carga debía negarse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_ENV es production', $e->getMessage());
        }

        $this->assertSame(0, DB::table('contrato')->count());
    }

    // 601 · Datos de demostración: con SEMILLA_DEMO=si la carga corre en producción, sin duplicar
    public function test_con_semilla_demo_carga_en_produccion_sin_duplicar(): void
    {
        config(['app.env' => 'production', 'semilla.demo' => true, 'semilla.fecha' => '2026-10-28']);

        // Como la corre infra: con --force (en producción db:seed pide confirmación sin él)
        $this->artisan('db:seed', ['--class' => DatosPruebaSeeder::class, '--force' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => DatosPruebaSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(3, DB::table('contrato')->count());
        $this->assertSame(3, DB::table('cobro')->count());
        $this->assertSame(0, DB::table('outbox_evento')->count());
    }

    public function test_con_semilla_demo_carga_con_una_base_que_no_es_local(): void
    {
        $original = config('database.default');
        config([
            'semilla.demo' => true,
            'database.connections.remota' => ['driver' => 'pgsql', 'host' => 'finanzas.postgres.database.azure.com'],
        ]);

        // La guarda lee la conexión por defecto: con SEMILLA_DEMO=si deja cargar; el vaciado se niega igual
        config(['database.default' => 'remota']);
        try {
            GuardaDatosPrueba::comprobar(esCarga: true);
            $this->addToAssertionCount(1);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no es local');
            GuardaDatosPrueba::comprobar();
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_se_niega_con_una_base_que_no_es_local(): void
    {
        $original = config('database.default');
        config([
            'database.default' => 'remota',
            'database.connections.remota' => ['driver' => 'pgsql', 'host' => '10.0.0.5'],
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no es local');
            (new DatosPruebaSeeder)->run();
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_rechaza_una_semilla_fecha_invalida(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cargar('28/10/2026');
    }

    private function cargar(?string $d): void
    {
        config(['semilla.fecha' => $d]);
        $this->seed(DatosPruebaSeeder::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function foto(): array
    {
        $foto = [];
        foreach (['contrato', 'reserva_saldo', 'cobro', 'envio_cobro'] as $tabla) {
            $foto[$tabla] = DB::table($tabla)->orderBy('id')->get()->map(fn (object $fila): array => (array) $fila)->all();
        }

        return $foto;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function cobros(): array
    {
        return DB::table('cobro')->orderBy('numero')->get()
            ->mapWithKeys(fn (object $c): array => [$c->id => [
                $c->numero,
                (float) $c->monto_neto,
                (string) $c->emitido_en,
                (string) $c->vence_en,
                $c->estado,
            ]])
            ->all();
    }

    private function contratoDe(string $obraId): TestResponse
    {
        return $this->getJson('/contratos?obraId='.$obraId, $this->encabezados());
    }

    /**
     * @return array<string, string>
     */
    private function encabezados(): array
    {
        return ['Authorization' => 'Bearer '.$this->firmarConRoles(['gerente'])];
    }
}
