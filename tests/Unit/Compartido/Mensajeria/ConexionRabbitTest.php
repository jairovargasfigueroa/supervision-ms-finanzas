<?php

namespace Tests\Unit\Compartido\Mensajeria;

use App\Compartido\Infraestructura\Mensajeria\ConexionRabbit;
use PHPUnit\Framework\TestCase;

/**
 * 601: datos de la conexión con RabbitMQ, sin conectarse. Sin TLS, como en desarrollo; con TLS (RABBITMQ_SSL=true),
 * cifrada y validando el certificado y el nombre del servidor.
 */
class ConexionRabbitTest extends TestCase
{
    public function test_sin_tls_conecta_en_claro_con_los_tiempos_de_siempre(): void
    {
        $config = (new ConexionRabbit('localhost', 5672, 'finanzas', 'clave'))->configuracion();

        $this->assertFalse($config->isSecure());
        $this->assertSame('tcp', $config->getNetworkProtocol());
        $this->assertSame('localhost', $config->getHost());
        $this->assertSame(5672, $config->getPort());
        $this->assertSame('finanzas', $config->getUser());
        $this->assertSame('/', $config->getVhost());
        $this->assertSame(5.0, $config->getConnectionTimeout());
        $this->assertSame(60.0, $config->getReadTimeout());
        $this->assertSame(30, $config->getHeartbeat());
    }

    public function test_con_tls_cifra_y_valida_el_certificado_y_el_nombre(): void
    {
        $config = (new ConexionRabbit('rabbitmq.azure.localhost', 5671, 'finanzas', 'clave', tls: true))->configuracion();

        $this->assertTrue($config->isSecure());
        $this->assertSame('tls', $config->getNetworkProtocol());
        $this->assertSame(5671, $config->getPort());
        $this->assertTrue($config->getSslVerify());
        $this->assertTrue($config->getSslVerifyName());
    }
}
