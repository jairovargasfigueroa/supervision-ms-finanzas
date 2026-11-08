<?php

namespace Tests\Utilidades;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Contratos\Infraestructura\Persistencia\ContratoModelo;
use App\Contratos\Infraestructura\Persistencia\ReservaSaldoModelo;
use Illuminate\Support\Str;

/**
 * Para las pruebas de la saga «aprobar planilla» (008) del lado de Finanzas: Obras se simula enviando sus comandos a
 * la cola `finanzas` del CanalMensajesFalso, y las respuestas se leen del outbox al publicarlo.
 *
 * Quien lo usa llama a `prepararCanal()` en `setUp` (después de `parent::setUp()`).
 */
trait MensajesSaga
{
    protected const OBRA_ID = '0b1a0000-0000-4000-8000-000000000001';

    protected const CONTRATO_ID = 'c0a10000-0000-4000-8000-000000000001';

    protected const PLANILLA_ID = '9a1a0000-0000-4000-8000-000000000001';

    /** `correlacionId` de la saga (en Obras es `saga_aprobacion.id`). */
    protected const SAGA_ID = '5a9a0000-0000-4000-8000-000000000001';

    protected CanalMensajesFalso $canal;

    protected function prepararCanal(): void
    {
        $this->canal = new CanalMensajesFalso;
        $this->app->instance(CanalMensajes::class, $this->canal);
    }

    protected function crearContrato(string $monto = '100000.00', string $id = self::CONTRATO_ID, string $obraId = self::OBRA_ID): void
    {
        ContratoModelo::query()->create([
            'id' => $id,
            'obra_id' => $obraId,
            'monto' => $monto,
            'plazo_dias' => 300,
            'anticipo_pct' => '10.00',
            'retencion_pct' => '7.00',
            'firmado_en' => '2026-10-20',
        ]);
    }

    protected function crearReserva(string $monto, string $estado = 'RESERVADA', string $planillaId = self::PLANILLA_ID, string $contratoId = self::CONTRATO_ID): string
    {
        $id = (string) Str::uuid();
        ReservaSaldoModelo::query()->create([
            'id' => $id,
            'contrato_id' => $contratoId,
            'planilla_id' => $planillaId,
            'monto' => $monto,
            'estado' => $estado,
        ]);

        return $id;
    }

    protected function crearCobro(string $numero, string $monto, string $emitidoEn = '2026-10-01', string $planillaId = '', string $contratoId = self::CONTRATO_ID): string
    {
        $id = (string) Str::uuid();
        CobroModelo::query()->create([
            'id' => $id,
            'contrato_id' => $contratoId,
            'planilla_id' => $planillaId !== '' ? $planillaId : (string) Str::uuid(),
            'numero' => $numero,
            'monto_neto' => $monto,
            'emitido_en' => $emitidoEn,
            'vence_en' => (new \DateTimeImmutable($emitidoEn))->modify('+30 days')->format('Y-m-d'),
            'estado' => 'PENDIENTE',
        ]);

        return $id;
    }

    /**
     * Sobre de un comando de Obras para Finanzas (docs/contratos/eventos.md).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    protected function comando(string $tipo, array $datos, ?string $id = null): array
    {
        return [
            'id' => $id ?? (string) Str::uuid(),
            'tipo' => $tipo,
            'version' => 1,
            'ocurridoEn' => '2026-10-29T14:30:00Z',
            'origen' => 'obras',
            'correlacionId' => self::SAGA_ID,
            'datos' => $datos,
        ];
    }

    /**
     * Deja el sobre en la cola `finanzas` y corre el consumidor hasta vaciarla.
     *
     * @param  array<string, mixed>  $sobre
     */
    protected function recibir(array $sobre): void
    {
        $this->canal->encolar('finanzas', (string) json_encode($sobre), 'comando.finanzas.'.$sobre['tipo']);
        $this->artisan('mensajeria:consumir')->assertSuccessful();
    }

    /**
     * Publica el outbox y devuelve los sobres enviados con la clave `evento.finanzas.<tipo>`, en orden.
     *
     * @return list<array<string, mixed>>
     */
    protected function respuestas(string $tipo): array
    {
        $this->artisan('mensajeria:publicar', ['--una-vez' => true])->assertSuccessful();

        return array_map(function (array $publicado) {
            $sobre = json_decode($publicado['cuerpo'], true);
            $this->assertIsArray($sobre);
            $this->assertSame($sobre['id'], $publicado['idMensaje']);

            return $sobre;
        }, $this->canal->publicadosEn('plataforma', 'evento.finanzas.'.$tipo));
    }

    /**
     * La única respuesta publicada (de cualquier tipo); comprueba que sea de `$tipo`, de Finanzas y con la saga.
     *
     * @return array<string, mixed>
     */
    protected function unicaRespuesta(string $tipo): array
    {
        $respuestas = $this->respuestas($tipo);
        $this->assertCount(1, $respuestas);
        $this->assertCount(1, $this->canal->publicados, 'Una sola respuesta, sin reintentos ni fallidos.');
        $sobre = $respuestas[0];
        $this->assertSame($tipo, $sobre['tipo']);
        $this->assertSame('finanzas', $sobre['origen']);
        $this->assertSame(1, $sobre['version']);
        $this->assertSame(self::SAGA_ID, $sobre['correlacionId']);

        return $sobre;
    }
}
