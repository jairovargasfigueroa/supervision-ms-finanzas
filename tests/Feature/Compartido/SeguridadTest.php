<?php

namespace Tests\Feature\Compartido;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Toda ruta de la API exige token sin tener que declararlo, y toda respuesta lleva los encabezados de seguridad
 * (convenciones, «Seguridad»).
 */
class SeguridadTest extends TestCase
{
    public function test_una_ruta_nueva_de_la_api_exige_token_sin_declararlo(): void
    {
        Route::middleware('api')->get('/ruta-de-prueba', fn () => response()->json(['ok' => true]));

        $this->getJson('/ruta-de-prueba')
            ->assertStatus(401)
            ->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_envia_los_encabezados_de_seguridad(): void
    {
        $this->get('/salud')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }
}
