<?php

namespace App\Compartido\Infraestructura\Mensajeria;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Base de cada manejador de mensajes: inbox en la misma transacción que el trabajo (docs/convenciones.md).
 *
 * Si el `id` del sobre ya se procesó, no hace nada. Si `manejar` lanza, se deshace todo (también el registro
 * en `mensaje_procesado`) y `mensajeria:consumir` reintenta el mensaje.
 */
abstract class ConsumidorBase
{
    final public function atender(Sobre $sobre): void
    {
        DB::transaction(function () use ($sobre): void {
            if (! MensajeProcesado::registrarSiEsNuevo($sobre->id)) {
                Log::info('Mensaje repetido: se descarta.', ['id' => $sobre->id, 'tipo' => $sobre->tipo]);

                return;
            }
            $this->manejar($sobre);
        });
    }

    /**
     * Hace el trabajo del mensaje y deja su respuesta en el outbox. Corre dentro de la transacción.
     */
    abstract protected function manejar(Sobre $sobre): void;
}
