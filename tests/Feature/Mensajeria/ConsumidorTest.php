<?php

namespace Tests\Feature\Mensajeria;

use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Compartido\Infraestructura\Mensajeria\ConsumidorBase;
use App\Compartido\Infraestructura\Mensajeria\MensajeProcesado;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
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
        $id = (string) Str::uuid();
        // Sin `datos`: el sobre es inválido y procesarlo falla siempre.
        $cuerpo = json_encode(['id' => $id, 'tipo' => 'ObraRegistrada', 'correlacionId' => $id]);
        $this->canal->encolar('finanzas', (string) $cuerpo, 'evento.obras.ObraRegistrada');

        $this->artisan('mensajeria:consumir')->assertSuccessful();

        // Original + 2 reintentos en la misma cola, por el exchange por defecto.
        $this->assertSame(3, $this->canal->entregas);
        $reintentos = $this->canal->publicadosEn('', 'finanzas');
        $this->assertCount(2, $reintentos);
        $this->assertSame([1, 2], array_column(array_column($reintentos, 'encabezados'), 'x-intentos'));
        $this->assertSame($id, $reintentos[0]['idMensaje']);
        $this->assertSame('evento.obras.ObraRegistrada', $reintentos[1]['encabezados']['x-clave-ruteo']);

        $fallidos = $this->canal->publicadosEn('', 'plataforma.fallidos');
        $this->assertCount(1, $fallidos);
        $this->assertSame($cuerpo, $fallidos[0]['cuerpo']);
        $this->assertSame($id, $fallidos[0]['idMensaje']);
        $this->assertSame(3, $fallidos[0]['encabezados']['x-intentos']);
        $this->assertSame('finanzas', $fallidos[0]['encabezados']['x-cola-origen']);
        $this->assertSame('evento.obras.ObraRegistrada', $fallidos[0]['encabezados']['x-clave-ruteo']);
        $this->assertStringContainsString('datos', (string) $fallidos[0]['encabezados']['x-error']);

        $this->assertSame(0, MensajeProcesado::query()->count());
    }

    public function test_un_cuerpo_que_no_es_json_tambien_termina_en_fallidos(): void
    {
        $this->canal->encolar('finanzas', 'no es json', 'evento.obras.ObraRegistrada');

        $this->artisan('mensajeria:consumir')->assertSuccessful();

        $this->assertCount(1, $this->canal->publicadosEn('', 'plataforma.fallidos'));
    }

    public function test_un_tipo_sin_manejador_se_confirma_sin_hacer_nada(): void
    {
        $id = (string) Str::uuid();
        $cuerpo = json_encode(['id' => $id, 'tipo' => 'TipoDesconocido', 'version' => 1, 'correlacionId' => $id, 'datos' => ['x' => 1]]);
        $this->canal->encolar('finanzas', (string) $cuerpo, 'comando.finanzas.TipoDesconocido');

        $this->artisan('mensajeria:consumir')->assertSuccessful();

        $this->assertSame(1, $this->canal->entregas);
        $this->assertSame([], $this->canal->publicados);
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
