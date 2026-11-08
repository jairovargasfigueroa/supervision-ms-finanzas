<?php

namespace App\Compartido\Infraestructura\Mensajeria;

/**
 * Puerto hacia el broker de mensajes. En producción es ConexionRabbit; en las pruebas, un doble en memoria
 * (las pruebas no necesitan RabbitMQ).
 */
interface CanalMensajes
{
    /**
     * Publica un mensaje persistente en formato JSON y espera la confirmación del broker.
     *
     * @param  string  $exchange  `plataforma` para eventos y comandos; `''` (exchange por defecto) para enviar a una cola por su nombre
     * @param  array<string, string|int>  $encabezados
     */
    public function publicar(string $exchange, string $claveRuteo, string $cuerpo, string $idMensaje, array $encabezados = []): void;

    /**
     * Consume la cola hasta que se detenga la conexión. Cada mensaje se confirma (ack) cuando `$alRecibir`
     * termina sin lanzar; si lanza, el mensaje queda sin confirmar y el broker lo vuelve a entregar al reconectar.
     *
     * @param  callable(string $cuerpo, string $claveRuteo, array<string, mixed> $encabezados): void  $alRecibir
     */
    public function consumir(string $cola, callable $alRecibir): void;

    /** Cierra la conexión (la siguiente operación vuelve a conectar). */
    public function cerrar(): void;
}
