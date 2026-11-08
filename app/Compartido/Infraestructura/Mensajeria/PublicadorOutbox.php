<?php

namespace App\Compartido\Infraestructura\Mensajeria;

use Carbon\CarbonImmutable;

/**
 * Envía a RabbitMQ los mensajes pendientes de `outbox_evento`, en orden de creación, y los marca publicados.
 *
 * Si RabbitMQ falla a mitad de camino, los ya enviados quedan marcados y el resto se reintenta en la siguiente
 * vuelta (entrega «al menos una vez»: los consumidores descartan repetidos por el `id`).
 */
final readonly class PublicadorOutbox
{
    public function __construct(
        private CanalMensajes $canal,
        private string $exchange,
    ) {}

    /** @return int cuántos mensajes publicó */
    public function publicarPendientes(int $limite = 100): int
    {
        $pendientes = OutboxEvento::query()
            ->whereNull('publicado_en')
            ->orderBy('creado_en')
            ->limit($limite)
            ->get();

        foreach ($pendientes as $evento) {
            $this->canal->publicar($this->exchange, $evento->tipo, Sobre::json($evento->payload), $evento->id);
            $evento->publicado_en = CarbonImmutable::now();
            $evento->save();
        }

        return $pendientes->count();
    }
}
