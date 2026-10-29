<?php

namespace Tests\Feature\Mensajeria;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Aplicacion\ResumenCobros;
use App\Cobros\Dominio\Cobro;
use App\Cobros\Dominio\CorteResumen;
use App\Cobros\Dominio\EstadoCobro;
use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Cobros\Infraestructura\Persistencia\CobroRepositorioEloquent;
use App\Compartido\Dominio\CriteriosPagina;
use App\Compartido\Dominio\Pagina;
use App\Compartido\Infraestructura\Mensajeria\MensajeProcesado;
use App\Contratos\Infraestructura\Persistencia\ReservaSaldoModelo;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;

/**
 * 008, paso 4: `EmitirCobro {planillaId, reservaId, monto}` → `CobroEmitido {planillaId, cobroId, numero, venceEn}` o
 * `CobroFallido {planillaId, motivo}`.
 */
class EmitirCobroTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCanal();
        $this->crearContrato('100000.00');
        // 02:00 UTC del 30 de octubre: en La Paz todavía es el 29.
        CarbonImmutable::setTestNow('2026-10-30T02:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_emite_el_cobro_consume_la_reserva_y_responde_cobro_emitido(): void
    {
        $reservaId = $this->crearReserva('41881.80');

        $this->recibir($this->emitir($reservaId, 41881.80));

        $cobro = CobroModelo::query()->sole();
        $this->assertSame(self::CONTRATO_ID, $cobro->contrato_id);
        $this->assertSame(self::PLANILLA_ID, $cobro->planilla_id);
        $this->assertSame('COB-2026-0001', $cobro->numero);
        $this->assertSame('41881.80', $cobro->monto_neto);
        $this->assertSame('2026-10-29', $cobro->emitido_en->format('Y-m-d'), 'Fecha de hoy en La Paz.');
        $this->assertSame('2026-11-28', $cobro->vence_en->format('Y-m-d'), 'Emisión + 30 días.');
        $this->assertSame('PENDIENTE', $cobro->estado);
        $this->assertSame('CONSUMIDA', ReservaSaldoModelo::query()->findOrFail($reservaId)->estado);

        $respuesta = $this->unicaRespuesta('CobroEmitido');
        $this->assertSame([
            'planillaId' => self::PLANILLA_ID,
            'cobroId' => $cobro->id,
            'numero' => 'COB-2026-0001',
            'venceEn' => '2026-11-28',
        ], $respuesta['datos']);
    }

    public function test_otro_mensaje_para_la_misma_planilla_responde_con_el_cobro_existente(): void
    {
        $reservaId = $this->crearReserva('1000.00');

        $this->recibir($this->emitir($reservaId, 1000));
        $this->recibir($this->emitir($reservaId, 1000));

        $cobro = CobroModelo::query()->sole();
        $respuestas = $this->respuestas('CobroEmitido');
        $this->assertCount(2, $respuestas);
        $this->assertSame($respuestas[0]['datos'], $respuestas[1]['datos']);
        $this->assertSame($cobro->id, $respuestas[1]['datos']['cobroId']);
        $this->assertSame([], $this->respuestas('CobroFallido'));
    }

    public function test_el_mismo_mensaje_dos_veces_no_emite_dos_cobros(): void
    {
        $mensaje = $this->emitir($this->crearReserva('1000.00'), 1000);

        $this->recibir($mensaje);
        $this->recibir($mensaje);

        $this->assertSame(1, CobroModelo::query()->count());
        $this->assertSame(1, MensajeProcesado::query()->count());
        $this->assertCount(1, $this->respuestas('CobroEmitido'));
    }

    public function test_una_reserva_ya_liberada_responde_cobro_fallido_sin_emitir(): void
    {
        $reservaId = $this->crearReserva('1000.00', 'LIBERADA');

        $this->recibir($this->emitir($reservaId, 1000));

        $this->assertSame(0, CobroModelo::query()->count());
        $this->assertSame('LIBERADA', ReservaSaldoModelo::query()->findOrFail($reservaId)->estado);
        $respuesta = $this->unicaRespuesta('CobroFallido');
        $this->assertSame([
            'planillaId' => self::PLANILLA_ID,
            'motivo' => 'No se pudo emitir el cobro: la reserva de saldo ya no está vigente',
        ], $respuesta['datos']);
    }

    public function test_una_reserva_que_no_existe_responde_cobro_fallido_por_error(): void
    {
        $this->recibir($this->emitir('7e5e0000-0000-4000-8000-0000000000ff', 1000));

        $this->assertSame(0, CobroModelo::query()->count());
        $this->assertSame('No se pudo emitir el cobro por un error en Finanzas', $this->unicaRespuesta('CobroFallido')['datos']['motivo']);
    }

    public function test_un_monto_distinto_del_reservado_responde_cobro_fallido_por_error(): void
    {
        $reservaId = $this->crearReserva('1000.00');

        $this->recibir($this->emitir($reservaId, 1000.01));

        $this->assertSame(0, CobroModelo::query()->count());
        $this->assertSame('RESERVADA', ReservaSaldoModelo::query()->findOrFail($reservaId)->estado);
        $this->assertSame('No se pudo emitir el cobro por un error en Finanzas', $this->unicaRespuesta('CobroFallido')['datos']['motivo']);
    }

    public function test_un_error_al_guardar_el_cobro_responde_cobro_fallido_y_deja_la_reserva_vigente(): void
    {
        $reservaId = $this->crearReserva('1000.00');
        $this->app->bind(CobroRepositorio::class, fn () => new CobroRepositorioQueFalla(new CobroRepositorioEloquent));

        $this->recibir($this->emitir($reservaId, 1000));

        $this->assertSame(0, CobroModelo::query()->count());
        $this->assertSame('RESERVADA', ReservaSaldoModelo::query()->findOrFail($reservaId)->estado);
        $this->assertSame('No se pudo emitir el cobro por un error en Finanzas', $this->unicaRespuesta('CobroFallido')['datos']['motivo']);
        $this->assertSame(1, MensajeProcesado::query()->count(), 'El mensaje queda procesado: ya se respondió.');
    }

    /**
     * @return array<string, mixed>
     */
    private function emitir(string $reservaId, int|float|string $monto): array
    {
        return $this->comando('EmitirCobro', ['planillaId' => self::PLANILLA_ID, 'reservaId' => $reservaId, 'monto' => $monto]);
    }
}

/** Repositorio que falla al guardar el cobro, para probar «cualquier otro error al emitir». */
final readonly class CobroRepositorioQueFalla implements CobroRepositorio
{
    public function __construct(private CobroRepositorio $real) {}

    public function listar(?string $obraId, ?EstadoCobro $estado, CriteriosPagina $criterios): Pagina
    {
        return $this->real->listar($obraId, $estado, $criterios);
    }

    public function resumir(CorteResumen $corte): ResumenCobros
    {
        return $this->real->resumir($corte);
    }

    public function siguienteId(): string
    {
        return $this->real->siguienteId();
    }

    public function deLaPlanilla(string $planillaId): ?Cobro
    {
        return $this->real->deLaPlanilla($planillaId);
    }

    public function siguienteCorrelativo(int $anio): int
    {
        return $this->real->siguienteCorrelativo($anio);
    }

    public function guardar(Cobro $cobro): void
    {
        throw new RuntimeException('Fallo simulado al guardar el cobro.');
    }

    public function buscar(string $id): ?CobroConsultado
    {
        return $this->real->buscar($id);
    }

    public function marcarVencidos(DateTimeImmutable $hoy): int
    {
        return $this->real->marcarVencidos($hoy);
    }
}
