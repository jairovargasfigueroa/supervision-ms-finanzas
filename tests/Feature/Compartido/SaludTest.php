<?php

namespace Tests\Feature\Compartido;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaludTest extends TestCase
{
    public function test_salud_responde_ok_sin_token_y_sin_pedir_el_jwks(): void
    {
        Http::fake();

        $this->getJson('/salud')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);

        Http::assertNothingSent();
    }

    public function test_la_ruta_no_lleva_el_prefijo_api(): void
    {
        $this->getJson('/api/salud')->assertUnauthorized();
    }

    public function test_devuelve_el_x_request_id_que_llega(): void
    {
        $this->getJson('/salud', ['X-Request-Id' => 'abc-123'])
            ->assertHeader('X-Request-Id', 'abc-123');
    }

    public function test_genera_un_x_request_id_si_no_llega(): void
    {
        $id = $this->getJson('/salud')->headers->get('X-Request-Id');

        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
    }

    public function test_reemplaza_un_x_request_id_con_caracteres_no_permitidos(): void
    {
        $id = $this->getJson('/salud', ['X-Request-Id' => "malo\nlinea"])->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $id);
    }
}
