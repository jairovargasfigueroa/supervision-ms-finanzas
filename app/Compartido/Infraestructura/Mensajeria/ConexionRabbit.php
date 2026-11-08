<?php

namespace App\Compartido\Infraestructura\Mensajeria;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

/**
 * Conexión con RabbitMQ (php-amqplib 3). El exchange `plataforma` (topic, durable) y las colas los declara infra:
 * aquí no se redeclaran; solo se comprueba que la cola exista antes de consumirla.
 *
 * Conecta recién en la primera operación, así que crear la clase no necesita RabbitMQ.
 *
 * Con `tls` (601, `RABBITMQ_SSL=true`, puerto 5671) la conexión va cifrada y valida el certificado y el nombre del
 * servidor contra las CA del sistema (o la de `openssl.cafile`, para la CA local del ensayo).
 */
final class ConexionRabbit implements CanalMensajes
{
    /** Segundos entre latidos; el tiempo de lectura es el doble, como pide php-amqplib. */
    private const LATIDO = 30;

    private ?AbstractConnection $conexion = null;

    /** Canal de publicación, en modo confirmación (publisher confirms). */
    private ?AMQPChannel $canalPublicacion = null;

    public function __construct(
        private readonly string $host,
        private readonly int $puerto,
        private readonly string $usuario,
        private readonly string $clave,
        private readonly string $vhost = '/',
        private readonly bool $tls = false,
    ) {}

    public function publicar(string $exchange, string $claveRuteo, string $cuerpo, string $idMensaje, array $encabezados = []): void
    {
        $canal = $this->canalPublicacion();

        $mensaje = new AMQPMessage($cuerpo, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => $idMensaje,
            'application_headers' => new AMQPTable($encabezados),
        ]);
        $canal->basic_publish($mensaje, $exchange, $claveRuteo);
        // Si el broker lo rechaza (nack), el manejador de abajo lanza y el mensaje no se da por publicado.
        $canal->wait_for_pending_acks_returns(5.0);
    }

    public function consumir(string $cola, callable $alRecibir): void
    {
        $canal = $this->conexion()->channel();
        // Pasivo: falla si la cola no existe, sin cambiar cómo la declaró infra.
        $canal->queue_declare($cola, passive: true);
        $canal->basic_qos(0, 10, false);

        $canal->basic_consume($cola, '', false, false, false, false, function (AMQPMessage $mensaje) use ($alRecibir): void {
            $encabezados = $mensaje->has('application_headers')
                ? $mensaje->get('application_headers')->getNativeData()
                : [];
            $alRecibir($mensaje->getBody(), (string) $mensaje->getRoutingKey(), $encabezados);
            $mensaje->ack();
        });

        while ($canal->is_consuming()) {
            try {
                $canal->wait(null, false, self::LATIDO);
            } catch (AMQPTimeoutException) {
                // Sin mensajes en este intervalo: se sigue esperando (y se atienden los latidos).
            }
        }
    }

    public function cerrar(): void
    {
        try {
            $this->conexion?->close();
        } catch (\Throwable) {
            // La conexión ya estaba caída: no hay nada que cerrar.
        }
        $this->conexion = null;
        $this->canalPublicacion = null;
    }

    private function conexion(): AbstractConnection
    {
        if ($this->conexion === null || ! $this->conexion->isConnected()) {
            $this->conexion = AMQPConnectionFactory::create($this->configuracion());
            $this->canalPublicacion = null;
        }

        return $this->conexion;
    }

    /** Datos de la conexión (stream de php-amqplib); con TLS, valida el certificado y el nombre del servidor. */
    public function configuracion(): AMQPConnectionConfig
    {
        $config = new AMQPConnectionConfig;
        $config->setHost($this->host);
        $config->setPort($this->puerto);
        $config->setUser($this->usuario);
        $config->setPassword($this->clave);
        $config->setVhost($this->vhost);
        $config->setConnectionTimeout(5.0);
        $config->setReadTimeout(self::LATIDO * 2);
        $config->setWriteTimeout(self::LATIDO * 2);
        $config->setHeartbeat(self::LATIDO);
        if ($this->tls) {
            $config->setIsSecure(true);
            $config->setSslVerify(true);
            $config->setSslVerifyName(true);
        }

        return $config;
    }

    private function canalPublicacion(): AMQPChannel
    {
        $conexion = $this->conexion();
        if ($this->canalPublicacion === null || ! $this->canalPublicacion->is_open()) {
            $canal = $conexion->channel();
            $canal->set_nack_handler(function (AMQPMessage $mensaje): void {
                throw new RuntimeException('RabbitMQ no confirmó el mensaje '.$mensaje->get('message_id').'.');
            });
            $canal->confirm_select();
            $this->canalPublicacion = $canal;
        }

        return $this->canalPublicacion;
    }
}
