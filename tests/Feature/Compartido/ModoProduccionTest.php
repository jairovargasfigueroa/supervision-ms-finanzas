<?php

namespace Tests\Feature\Compartido;

use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Compartido\Infraestructura\Mensajeria\ConexionRabbit;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * 601: modo producción por variables. Con APP_DEBUG=false un error inesperado no muestra la traza ni el mensaje
 * interno, y RABBITMQ_SSL llega a la conexión con RabbitMQ.
 */
class ModoProduccionTest extends TestCase
{
    public function test_sin_debug_un_error_inesperado_no_muestra_la_traza_ni_el_mensaje_interno(): void
    {
        config(['app.debug' => false]);
        Route::get('/prueba-601-error', fn () => throw new RuntimeException('detalle interno de la base'));

        $respuesta = $this->getJson('/prueba-601-error');

        $respuesta->assertStatus(500);
        $this->assertStringNotContainsString('detalle interno de la base', (string) $respuesta->getContent());
        $respuesta->assertJsonMissingPath('trace');
        $respuesta->assertJsonMissingPath('exception');
        $respuesta->assertJsonMissingPath('file');
    }

    public function test_rabbitmq_ssl_activa_tls_en_la_conexion(): void
    {
        config(['services.rabbitmq.ssl' => true]);
        $this->app->forgetInstance(CanalMensajes::class);

        $canal = $this->app->make(CanalMensajes::class);

        $this->assertInstanceOf(ConexionRabbit::class, $canal);
        $this->assertTrue($canal->configuracion()->isSecure());
    }

    public function test_sin_rabbitmq_ssl_la_conexion_va_en_claro(): void
    {
        $canal = $this->app->make(CanalMensajes::class);

        $this->assertInstanceOf(ConexionRabbit::class, $canal);
        $this->assertFalse($canal->configuracion()->isSecure());
    }
}
