<?php

namespace Tests\Feature\Mensajeria;

use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Compartido\Infraestructura\Mensajeria\MensajeProcesado;
use App\Compartido\Infraestructura\Mensajeria\OutboxEvento;
use App\Contratos\Dominio\Contrato;
use App\Contratos\Infraestructura\Persistencia\ContratoModelo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Utilidades\CanalMensajesFalso;

/**
 * 002: Finanzas consume `ObraRegistrada` y responde `ContratoRegistrado` o `ContratoRechazado` por el outbox.
 */
class ObraRegistradaTest extends TestCase
{
    use RefreshDatabase;

    private const OBRA_ID = '0b1a0000-0000-4000-8000-000000000001';

    private const CORRELACION_ID = 'c0e10000-0000-4000-8000-000000000001';

    private CanalMensajesFalso $canal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canal = new CanalMensajesFalso;
        $this->app->instance(CanalMensajes::class, $this->canal);
    }

    public function test_un_contrato_valido_se_registra_y_publica_contrato_registrado(): void
    {
        $this->recibir($this->obraRegistrada());

        $contrato = ContratoModelo::query()->sole();
        $this->assertSame(self::OBRA_ID, $contrato->obra_id);
        $this->assertSame('1250000.00', $contrato->monto);
        $this->assertSame('1250000.00', $contrato->saldo_disponible);
        $this->assertSame(300, $contrato->plazo_dias);
        $this->assertSame('10.00', $contrato->anticipo_pct);
        $this->assertSame('7.00', $contrato->retencion_pct);
        $this->assertSame('2026-10-20', $contrato->firmado_en->format('Y-m-d'));

        $publicado = $this->publicarUnico('evento.finanzas.ContratoRegistrado');
        $this->assertSame('ContratoRegistrado', $publicado['tipo']);
        $this->assertSame('finanzas', $publicado['origen']);
        $this->assertSame(1, $publicado['version']);
        $this->assertSame(self::CORRELACION_ID, $publicado['correlacionId']);
        $this->assertEquals(['obraId' => self::OBRA_ID, 'contratoId' => $contrato->id], $publicado['datos']);
    }

    public function test_un_contrato_invalido_publica_contrato_rechazado_con_los_motivos(): void
    {
        $this->recibir($this->obraRegistrada(contrato: ['monto' => 0, 'anticipoPct' => 60, 'retencionPct' => 40]));

        $this->assertSame(0, ContratoModelo::query()->count());
        $publicado = $this->publicarUnico('evento.finanzas.ContratoRechazado');
        $this->assertSame('ContratoRechazado', $publicado['tipo']);
        $this->assertSame(self::CORRELACION_ID, $publicado['correlacionId']);
        $this->assertEquals([
            'obraId' => self::OBRA_ID,
            'motivo' => 'El monto del contrato debe ser mayor a 0; El anticipo más la retención deben sumar menos de 100 %',
        ], $publicado['datos']);
    }

    public function test_cada_motivo_de_rechazo_llega_con_su_texto(): void
    {
        $this->recibir($this->obraRegistrada(contrato: ['plazoDias' => 0, 'anticipoPct' => -1]));

        $publicado = $this->publicarUnico('evento.finanzas.ContratoRechazado');
        $this->assertSame(Contrato::MOTIVO_PLAZO.'; '.Contrato::MOTIVO_PORCENTAJES, $publicado['datos']['motivo']);
    }

    public function test_el_mismo_mensaje_dos_veces_no_crea_dos_contratos(): void
    {
        $mensaje = $this->obraRegistrada();

        $this->recibir($mensaje);
        $this->recibir($mensaje);

        $this->assertSame(1, ContratoModelo::query()->count());
        $this->assertSame(1, OutboxEvento::query()->count());
        $this->assertSame(1, MensajeProcesado::query()->count());
        $this->assertSame([], $this->canal->publicados, 'Ningún reintento ni fallido.');
    }

    public function test_otro_mensaje_de_la_misma_obra_no_crea_otro_contrato(): void
    {
        $this->recibir($this->obraRegistrada());
        $this->recibir($this->obraRegistrada(contrato: ['monto' => 999]));

        $this->assertSame('1250000.00', ContratoModelo::query()->sole()->monto);
        $this->assertSame(1, OutboxEvento::query()->count());
        $this->assertSame(2, MensajeProcesado::query()->count());
    }

    public function test_acepta_montos_en_texto_y_los_redondea_a_dos_decimales(): void
    {
        $this->recibir($this->obraRegistrada(contrato: ['monto' => '1250000.005', 'anticipoPct' => '10.5']));

        $contrato = ContratoModelo::query()->sole();
        $this->assertSame('1250000.01', $contrato->monto);
        $this->assertSame('10.50', $contrato->anticipo_pct);
    }

    public function test_un_mensaje_sin_contrato_no_se_rechaza_sino_que_va_directo_a_fallidos(): void
    {
        $mensaje = $this->obraRegistrada();
        unset($mensaje['datos']['contrato']);

        $this->recibir($mensaje);

        $this->assertSame(0, ContratoModelo::query()->count());
        $this->assertSame(0, OutboxEvento::query()->count());
        $this->assertSame(0, MensajeProcesado::query()->count());
        $fallidos = $this->canal->publicadosEn('', 'plataforma.fallidos');
        $this->assertCount(1, $fallidos);
        $this->assertSame('ObraRegistrada sin contrato.', $fallidos[0]['encabezados']['x-error']);
        // Sin reintentos: un mensaje inválido no mejora al repetirlo.
        $this->assertSame(1, $fallidos[0]['encabezados']['x-intentos']);
        $this->assertSame([], $this->canal->publicadosEn('', 'finanzas'));
    }

    /**
     * Sobre `ObraRegistrada` como lo publica Obras (docs/contratos/eventos.md).
     *
     * @param  array<string, mixed>  $contrato  campos del contrato que se cambian
     * @return array<string, mixed>
     */
    private function obraRegistrada(array $contrato = []): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tipo' => 'ObraRegistrada',
            'version' => 1,
            'ocurridoEn' => '2026-10-29T14:30:00Z',
            'origen' => 'obras',
            'correlacionId' => self::CORRELACION_ID,
            'datos' => [
                'obraId' => self::OBRA_ID,
                'nombre' => 'Edificio Las Palmas',
                'fechaInicio' => '2026-11-02',
                'asignaciones' => [
                    'residenteId' => (string) Str::uuid(),
                    'maestroId' => (string) Str::uuid(),
                    'almaceneroId' => (string) Str::uuid(),
                    'clienteId' => (string) Str::uuid(),
                ],
                'contrato' => [
                    'monto' => 1250000.00,
                    'plazoDias' => 300,
                    'anticipoPct' => 10,
                    'retencionPct' => 7,
                    'firmadoEn' => '2026-10-20',
                    ...$contrato,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $sobre
     */
    private function recibir(array $sobre): void
    {
        $this->canal->encolar('finanzas', (string) json_encode($sobre), 'evento.obras.ObraRegistrada');
        $this->artisan('mensajeria:consumir')->assertSuccessful();
    }

    /**
     * Publica el outbox y devuelve el único sobre enviado con esa clave.
     *
     * @return array<string, mixed>
     */
    private function publicarUnico(string $claveRuteo): array
    {
        $this->artisan('mensajeria:publicar', ['--una-vez' => true])->assertSuccessful();
        $publicados = $this->canal->publicadosEn('plataforma', $claveRuteo);
        $this->assertCount(1, $publicados);
        $this->assertCount(1, $this->canal->publicados);

        $sobre = json_decode($publicados[0]['cuerpo'], true);
        $this->assertIsArray($sobre);
        $this->assertSame($sobre['id'], $publicados[0]['idMensaje']);

        return $sobre;
    }
}
