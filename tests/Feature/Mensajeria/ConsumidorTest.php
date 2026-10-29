<?php

namespace Tests\Feature\Mensajeria;

use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Compartido\Infraestructura\Mensajeria\ConsumidorBase;
use App\Compartido\Infraestructura\Mensajeria\MensajeProcesado;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
use App\Contratos\Aplicacion\Puertos\ContratoRepositorio;
use App\Contratos\Dominio\Contrato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Tests\Utilidades\CanalMensajesFalso;

/**
 * `mensajeria:consumir` (reintentos y fallidos) e inbox de ConsumidorBase (002).
 */
class ConsumidorTest extends TestCase
{
    use RefreshDatabase;

    private CanalMensajesFalso $canal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canal = new CanalMensajesFalso;
        $this->app->instance(CanalMensajes::class, $this->canal);
    }

    public function test_un_mensaje_que_falla_se_reintenta_y_al_tercer_fallo_va_a_fallidos(): void
    {
        // La base «se cae» al atender un ObraRegistrada válido: es un fallo que se puede reintentar.
        $this->app->instance(ContratoRepositorio::class, new RepositorioQueFalla);
        $id = (string) Str::uuid();
        $cuerpo = $this->sobre($id, 'ObraRegistrada', [
            'obraId' => (string) Str::uuid(),
            'contrato' => ['monto' => 1000, 'plazoDias' => 30, 'anticipoPct' => 10, 'retencionPct' => 7, 'firmadoEn' => '2026-10-20'],
        ]);
        $this->canal->encolar('finanzas', $cuerpo, 'evento.obras.ObraRegistrada');

        $this->artisan('mensajeria:consumir')->assertSuccessful();

        // Original + 2 reintentos en la misma cola, por el exchange por defecto y con el cuerpo sin tocar.
        $this->assertSame(3, $this->canal->entregas);
        $reintentos = $this->canal->publicadosEn('', 'finanzas');
        $this->assertCount(2, $reintentos);
        $this->assertSame([1, 2], array_column(array_column($reintentos, 'encabezados'), 'x-intentos'));
        $this->assertSame([$cuerpo, $cuerpo], array_column($reintentos, 'cuerpo'));
        $this->assertSame($id, $reintentos[0]['idMensaje']);
        $this->assertSame('evento.obras.ObraRegistrada', $reintentos[1]['encabezados']['x-clave-origen']);
        $this->assertSame('finanzas', $reintentos[1]['encabezados']['x-cola-origen']);

        $fallido = $this->unicoFallido();
        $this->assertSame($cuerpo, $fallido['cuerpo']);
        $this->assertSame($id, $fallido['idMensaje']);
        $this->assertSame([
            'x-intentos' => 3,
            'x-error' => 'la base no responde',
            'x-cola-origen' => 'finanzas',
            'x-clave-origen' => 'evento.obras.ObraRegistrada',
        ], $fallido['encabezados']);

        $this->assertSame(0, MensajeProcesado::query()->count());
    }

    public function test_un_sobre_con_datos_incompletos_va_directo_a_fallidos(): void
    {
        $id = (string) Str::uuid();
        $cuerpo = (string) json_encode(['id' => $id, 'tipo' => 'ObraRegistrada', 'version' => 1, 'correlacionId' => $id]);
        $this->canal->encolar('finanzas', $cuerpo, 'evento.obras.ObraRegistrada');

        $this->artisan('mensajeria:consumir')->assertSuccessful();

        $this->assertSame(1, $this->canal->entregas);
        $this->assertSame([], $this->canal->publicadosEn('', 'finanzas'));
        $fallido = $this->unicoFallido();
        $this->assertSame($cuerpo, $fallido['cuerpo']);
        $this->assertSame(1, $fallido['encabezados']['x-intentos']);
        $this->assertSame('El sobre no tiene datos (objeto).', $fallido['encabezados']['x-error']);
        $this->assertSame('evento.obras.ObraRegistrada', $fallido['encabezados']['x-clave-origen']);
        $this->assertSame(0, MensajeProcesado::query()->count());
    }

    public function test_un_cuerpo_que_no_es_json_va_directo_a_fallidos(): void
    {
        $this->canal->encolar('finanzas', 'no es json', 'evento.obras.ObraRegistrada');

        $this->artisan('mensajeria:consumir')->assertSuccessful();

        $this->assertSame(1, $this->canal->entregas);
        $this->assertSame('no es json', $this->unicoFallido()['cuerpo']);
    }

    public function test_un_sobre_de_otra_version_va_directo_a_fallidos(): void
    {
        $id = (string) Str::uuid();
        $this->canal->encolar('finanzas', $this->sobre($id, 'ObraRegistrada', ['obraId' => $id], version: 2), 'evento.obras.ObraRegistrada');

        $this->artisan('mensajeria:consumir')->assertSuccessful();

        $this->assertSame(1, $this->canal->entregas);
        $this->assertSame('El sobre no es de la versión 1.', $this->unicoFallido()['encabezados']['x-error']);
    }

    public function test_un_tipo_sin_consumidor_va_directo_a_fallidos(): void
    {
        $id = (string) Str::uuid();
        $this->canal->encolar('finanzas', $this->sobre($id, 'ReservarSaldo', ['planillaId' => $id]), 'comando.finanzas.ReservarSaldo');

        $this->artisan('mensajeria:consumir')->assertSuccessful();

        $this->assertSame(1, $this->canal->entregas);
        $fallido = $this->unicoFallido();
        $this->assertSame('El tipo ReservarSaldo no tiene consumidor en la cola finanzas.', $fallido['encabezados']['x-error']);
        $this->assertSame('comando.finanzas.ReservarSaldo', $fallido['encabezados']['x-clave-origen']);
        $this->assertSame(0, MensajeProcesado::query()->count());
    }

    public function test_el_inbox_procesa_una_sola_vez_el_mismo_id(): void
    {
        $manejador = new ManejadorDePrueba;
        $sobre = Sobre::nuevo('Prueba', ['a' => 1], 'corr');

        $manejador->atender($sobre);
        $manejador->atender($sobre);

        $this->assertSame(1, $manejador->veces);
        $this->assertTrue(MensajeProcesado::query()->whereKey($sobre->id)->exists());
    }

    public function test_si_el_manejador_falla_el_id_no_queda_procesado(): void
    {
        $manejador = new ManejadorDePrueba(fallar: true);
        $sobre = Sobre::nuevo('Prueba', ['a' => 1], 'corr');

        try {
            $manejador->atender($sobre);
            $this->fail('Debía lanzar.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, MensajeProcesado::query()->count());
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function sobre(string $id, string $tipo, array $datos, int $version = 1): string
    {
        return (string) json_encode([
            'id' => $id, 'tipo' => $tipo, 'version' => $version, 'ocurridoEn' => '2026-10-29T14:30:00Z',
            'origen' => 'obras', 'correlacionId' => $id, 'datos' => $datos,
        ]);
    }

    /**
     * @return array{exchange: string, claveRuteo: string, cuerpo: string, idMensaje: string, encabezados: array<string, string|int>}
     */
    private function unicoFallido(): array
    {
        $fallidos = $this->canal->publicadosEn('', 'plataforma.fallidos');
        $this->assertCount(1, $fallidos);

        return $fallidos[0];
    }
}

/** Manejador mínimo para probar el inbox de ConsumidorBase. */
final class ManejadorDePrueba extends ConsumidorBase
{
    public int $veces = 0;

    public function __construct(private readonly bool $fallar = false) {}

    protected function manejar(Sobre $sobre): void
    {
        $this->veces++;
        if ($this->fallar) {
            throw new RuntimeException('falla de prueba');
        }
    }
}

/** Repositorio que simula una base caída. */
final class RepositorioQueFalla implements ContratoRepositorio
{
    public function siguienteId(): string
    {
        throw new RuntimeException('la base no responde');
    }

    public function existeParaObra(string $obraId): bool
    {
        throw new RuntimeException('la base no responde');
    }

    public function guardar(Contrato $contrato): void
    {
        throw new RuntimeException('la base no responde');
    }
}
