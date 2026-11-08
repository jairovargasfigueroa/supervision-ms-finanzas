<?php

namespace App\Console\Commands;

use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Compartido\Infraestructura\Mensajeria\PublicadorOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Proceso largo que publica el outbox en el exchange `plataforma` cada segundo (docs/convenciones.md).
 */
class MensajeriaPublicar extends Command
{
    protected $signature = 'mensajeria:publicar {--una-vez : publica los pendientes una sola vez y termina}';

    protected $description = 'Publica en RabbitMQ los eventos pendientes del outbox (cada 1 s)';

    private bool $detenido = false;

    public function handle(CanalMensajes $canal): int
    {
        $publicador = new PublicadorOutbox($canal, (string) config('services.rabbitmq.exchange'));

        if ($this->option('una-vez')) {
            $this->info('Publicados: '.$publicador->publicarPendientes());

            return self::SUCCESS;
        }

        // En Linux (despliegue) termina la vuelta en curso antes de detenerse; en Windows se detiene con Ctrl+C.
        if (extension_loaded('pcntl')) {
            $this->trap([SIGINT, SIGTERM], function (): void {
                $this->detenido = true;
            });
        }

        $this->info('Publicando el outbox cada segundo (Ctrl+C para detener).');
        while (! $this->detenido) {
            try {
                $publicados = $publicador->publicarPendientes();
                if ($publicados > 0) {
                    Log::info('Outbox publicado.', ['cantidad' => $publicados]);
                }
            } catch (Throwable $e) {
                // RabbitMQ o la base no responden: se reconecta en la siguiente vuelta.
                Log::error('No se pudo publicar el outbox.', ['error' => $e->getMessage()]);
                $canal->cerrar();
            }
            Sleep::for(1)->second();
        }

        return self::SUCCESS;
    }
}
