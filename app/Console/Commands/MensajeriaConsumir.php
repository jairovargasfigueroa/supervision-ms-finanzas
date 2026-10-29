<?php

namespace App\Console\Commands;

use App\Cobros\Infraestructura\Mensajeria\EmitirCobroManejador;
use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Compartido\Infraestructura\Mensajeria\ConsumidorBase;
use App\Compartido\Infraestructura\Mensajeria\MensajeInvalidoException;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
use App\Contratos\Infraestructura\Mensajeria\LiberarReservaManejador;
use App\Contratos\Infraestructura\Mensajeria\ObraRegistradaManejador;
use App\Contratos\Infraestructura\Mensajeria\ReservarSaldoManejador;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use Throwable;

/**
 * Proceso largo que consume la cola `finanzas` (docs/convenciones.md, «Eventos y comandos»).
 *
 * Cada mensaje va al manejador de su `tipo`, que aplica el inbox. Si procesarlo falla, se vuelve a publicar en la
 * misma cola (exchange por defecto, cuerpo sin tocar) con `x-intentos` + 1 y se confirma el original; al tercer fallo
 * va a la cola `plataforma.fallidos`. Un mensaje inválido (MensajeInvalidoException: no es JSON, sobre incompleto o de
 * otra versión, datos incompletos o `tipo` sin consumidor en esta cola) va directo a `plataforma.fallidos`.
 * Encabezados: `x-intentos`, `x-error`, `x-cola-origen` y `x-clave-origen` (docs/contratos.md).
 */
class MensajeriaConsumir extends Command
{
    /** Al llegar a este número de intentos fallidos, el mensaje va a `plataforma.fallidos`. */
    public const MAXIMO_INTENTOS = 3;

    protected $signature = 'mensajeria:consumir';

    protected $description = 'Consume la cola finanzas de RabbitMQ (proceso largo)';

    public function handle(CanalMensajes $canal): int
    {
        $cola = (string) config('services.rabbitmq.cola');
        $this->info("Consumiendo la cola {$cola} (Ctrl+C para detener).");

        while (true) {
            try {
                $canal->consumir($cola, function (string $cuerpo, string $claveRuteo, array $encabezados) use ($canal, $cola): void {
                    $this->procesar($canal, $cola, $cuerpo, $claveRuteo, $encabezados);
                });

                return self::SUCCESS;
            } catch (AMQPExceptionInterface $e) {
                // Se cayó la conexión: los mensajes sin confirmar vuelven a la cola. Se reconecta en 5 s.
                Log::error('Se perdió la conexión con RabbitMQ.', ['error' => $e->getMessage()]);
                $canal->cerrar();
                Sleep::for(5)->seconds();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $encabezados
     */
    private function procesar(CanalMensajes $canal, string $cola, string $cuerpo, string $claveRuteo, array $encabezados): void
    {
        try {
            $sobre = Sobre::desdeJson($cuerpo);
            $manejador = $this->manejadores()[$sobre->tipo] ?? null;
            if ($manejador === null) {
                throw new MensajeInvalidoException("El tipo {$sobre->tipo} no tiene consumidor en la cola {$cola}.");
            }
            app($manejador)->atender($sobre);
        } catch (MensajeInvalidoException $e) {
            $this->reintentarOFallar($canal, $cola, $cuerpo, $claveRuteo, $encabezados, $e, reintentar: false);
        } catch (Throwable $e) {
            $this->reintentarOFallar($canal, $cola, $cuerpo, $claveRuteo, $encabezados, $e, reintentar: true);
        }
    }

    /**
     * @param  array<string, mixed>  $encabezados
     */
    private function reintentarOFallar(CanalMensajes $canal, string $cola, string $cuerpo, string $claveRuteo, array $encabezados, Throwable $error, bool $reintentar): void
    {
        $intentos = (int) ($encabezados['x-intentos'] ?? 0) + 1;
        $idMensaje = $this->idDe($cuerpo);
        $nuevos = [
            ...$this->escalares($encabezados),
            'x-intentos' => $intentos,
            // Solo el mensaje del error: los textos de este servicio no llevan datos del mensaje.
            'x-error' => mb_substr($error->getMessage(), 0, 1000),
            'x-cola-origen' => $cola,
            // Al reintentar por el exchange por defecto la clave pasa a ser el nombre de la cola: se guarda la original.
            'x-clave-origen' => is_string($encabezados['x-clave-origen'] ?? null) ? $encabezados['x-clave-origen'] : $claveRuteo,
        ];
        $contexto = ['id' => $idMensaje, 'intentos' => $intentos, 'error' => $error->getMessage()];

        if (! $reintentar || $intentos >= self::MAXIMO_INTENTOS) {
            $canal->publicar('', (string) config('services.rabbitmq.cola_fallidos'), $cuerpo, $idMensaje, $nuevos);
            Log::error('Mensaje enviado a fallidos.', $contexto);

            return;
        }

        $canal->publicar('', $cola, $cuerpo, $idMensaje, $nuevos);
        Log::warning('Falló un mensaje: se reintenta.', $contexto);
    }

    /**
     * Manejador de cada `tipo` del sobre. Un `tipo` que no está aquí va a `plataforma.fallidos`.
     *
     * @return array<string, class-string<ConsumidorBase>>
     */
    private function manejadores(): array
    {
        return [
            'ObraRegistrada' => ObraRegistradaManejador::class,
            // 008 · saga «aprobar planilla».
            'ReservarSaldo' => ReservarSaldoManejador::class,
            'EmitirCobro' => EmitirCobroManejador::class,
            'LiberarReserva' => LiberarReservaManejador::class,
        ];
    }

    private function idDe(string $cuerpo): string
    {
        $sobre = json_decode($cuerpo, true);
        $id = is_array($sobre) ? ($sobre['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $encabezados
     * @return array<string, string|int>
     */
    private function escalares(array $encabezados): array
    {
        $resultado = [];
        foreach ($encabezados as $nombre => $valor) {
            if (is_string($valor) || is_int($valor)) {
                $resultado[$nombre] = $valor;
            }
        }

        return $resultado;
    }
}
