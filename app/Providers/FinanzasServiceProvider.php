<?php

namespace App\Providers;

use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Infraestructura\Persistencia\CobroRepositorioEloquent;
use App\Compartido\Infraestructura\Auth\ValidadorTokenKeycloak;
use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;
use App\Compartido\Infraestructura\Mensajeria\ConexionRabbit;
use App\Contratos\Aplicacion\Puertos\ContratoRepositorio;
use App\Contratos\Aplicacion\Puertos\PublicadorEventos;
use App\Contratos\Infraestructura\Mensajeria\PublicadorEventosOutbox;
use App\Contratos\Infraestructura\Persistencia\ContratoRepositorioEloquent;
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

        // Puertos del contexto Contratos (002).
        $this->app->bind(ContratoRepositorio::class, ContratoRepositorioEloquent::class);
        $this->app->bind(PublicadorEventos::class, PublicadorEventosOutbox::class);

        // Puertos del contexto Cobros (006).
        $this->app->bind(CobroRepositorio::class, CobroRepositorioEloquent::class);

        // Conecta recién en la primera publicación o consumo (002).
        $this->app->singleton(CanalMensajes::class, fn () => new ConexionRabbit(
            host: (string) config('services.rabbitmq.host'),
            puerto: (int) config('services.rabbitmq.puerto'),
            usuario: (string) config('services.rabbitmq.usuario'),
            clave: (string) config('services.rabbitmq.clave'),
        ));
    }
}
