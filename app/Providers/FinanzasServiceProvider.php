<?php

namespace App\Providers;

use App\Compartido\Infraestructura\Auth\ValidadorTokenKeycloak;
use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Compartido\Infraestructura\Mensajeria\ConexionRabbit;
use Illuminate\Support\ServiceProvider;

/**
 * Enlaza los puertos con sus implementaciones y arma los servicios compartidos.
 */
class FinanzasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Se crea recién cuando llega una petición con token; no pide el JWKS al arrancar.
        $this->app->singleton(ValidadorTokenKeycloak::class, fn () => new ValidadorTokenKeycloak(
            emisor: (string) config('services.keycloak.emisor'),
            audiencia: (string) config('services.keycloak.audiencia'),
        ));

        // Conecta recién en la primera publicación o consumo (002).
        $this->app->singleton(CanalMensajes::class, fn () => new ConexionRabbit(
            host: (string) config('services.rabbitmq.host'),
            puerto: (int) config('services.rabbitmq.puerto'),
            usuario: (string) config('services.rabbitmq.usuario'),
            clave: (string) config('services.rabbitmq.clave'),
        ));
    }
}
