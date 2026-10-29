<?php

namespace App\Providers;

use App\Compartido\Infraestructura\Auth\ValidadorTokenKeycloak;
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
    }
}
