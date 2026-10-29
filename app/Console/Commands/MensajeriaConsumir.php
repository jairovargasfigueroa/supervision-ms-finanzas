<?php

namespace App\Console\Commands;

use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Compartido\Infraestructura\Mensajeria\ConsumidorBase;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
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
 * misma cola con el encabezado `x-intentos` + 1 y se confirma el original; al tercer fallo va a la cola
 * `plataforma.fallidos` (exchange por defecto) con el error.
 */
class MensajeriaConsumir extends Command
{
    /** Fallos que se toleran antes de mandar el mensaje a `plataforma.fallidos`. */
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
                Log::warning('Mensaje sin manejador: se confirma sin hacer nada.', ['id' => $sobre->id, 'tipo' => $sobre->tipo]);

                return;
            }
            app($manejador)->atender($sobre);
        } catch (Throwable $e) {
            $this->reintentarOFallar($canal, $cola, $cuerpo, $claveRuteo, $encabezados, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $encabezados
     */
    private function reintentarOFallar(CanalMensajes $canal, string $cola, string $cuerpo, string $claveRuteo, array $encabezados, Throwable $error): void
    {
        $intentos = (int) ($encabezados['x-intentos'] ?? 0) + 1;
        $idMensaje = $this->idDe($cuerpo);
        $nuevos = [
            ...$this->escalares($encabezados),
            'x-intentos' => $intentos,
            // Al reintentar, la clave de enrutamiento pasa a ser el nombre de la cola: se guarda la original.
            'x-clave-ruteo' => is_string($encabezados['x-clave-ruteo'] ?? null) ? $encabezados['x-clave-ruteo'] : $claveRuteo,
        ];
        $contexto = ['id' => $idMensaje, 'intentos' => $intentos, 'error' => $error->getMessage()];

        if ($intentos >= self::MAXIMO_INTENTOS) {
            $nuevos['x-error'] = mb_substr($error::class.': '.$error->getMessage(), 0, 1000);
            $nuevos['x-cola-origen'] = $cola;
            $canal->publicar('', (string) config('services.rabbitmq.cola_fallidos'), $cuerpo, $idMensaje, $nuevos);
            Log::error('Mensaje enviado a fallidos.', $contexto);

            return;
        }

        $canal->publicar('', $cola, $cuerpo, $idMensaje, $nuevos);
        Log::warning('Falló un mensaje: se reintenta.', $contexto);
    }

    /**
     * Manejador de cada `tipo` del sobre. Los que no están aquí se confirman sin hacer nada.
     *
     * @return array<string, class-string<ConsumidorBase>>
     */
    private function manejadores(): array
    {
        return [];
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
