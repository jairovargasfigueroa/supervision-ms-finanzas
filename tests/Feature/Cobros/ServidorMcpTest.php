<?php

namespace Tests\Feature\Cobros;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Utilidades\TokensPrueba;

/**
 * 501: servidor MCP de solo lectura en `POST /mcp` (sin estado, respuestas JSON), solo para `agente_ia`.
 */
class ServidorMcpTest extends TestCase
{
    use RefreshDatabase;
    use TokensPrueba;

    /** Versión común del protocolo en la plataforma (501, diseño). */
    private const VERSION_MCP = '2025-11-25';

    private const REQUEST_ID = 'ejecucion-prueba-1';

    private int $siguienteId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
    }

    private function tokenAgente(): string
    {
        return $this->firmarConRoles(['agente_ia'], 'ia-agentes');
    }

    /**
     * Petición JSON-RPC a `/mcp` (como el cliente MCP de IA: sin `_meta` de la 2026-07-28, con el encabezado de versión).
     *
     * @param  array<string, mixed>  $params
     */
    private function rpc(string $metodo, array $params = [], ?string $token = null, bool $conToken = true): TestResponse
    {
        $encabezados = [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::VERSION_MCP,
            'X-Request-Id' => self::REQUEST_ID,
        ];
        if ($conToken) {
            $encabezados['Authorization'] = 'Bearer '.($token ?? $this->tokenAgente());
        }

        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => $this->siguienteId++,
            'method' => $metodo,
            'params' => (object) $params,
        ], $encabezados);
    }

    private function inicializar(?string $token = null, bool $conToken = true): TestResponse
    {
        return $this->rpc('initialize', [
            'protocolVersion' => self::VERSION_MCP,
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'prueba', 'version' => '1.0.0'],
        ], $token, $conToken);
    }

    public function test_sin_token_responde_401(): void
    {
        $this->inicializar(conToken: false)
            ->assertUnauthorized()
            ->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    public function test_con_un_token_invalido_responde_401(): void
    {
        $this->inicializar($this->firmarCon($this->datosToken(), 'otra'))
            ->assertUnauthorized()
            ->assertJsonPath('codigo', 'NO_AUTENTICADO');
    }

    /** @return array<string, array{list<string>, string}> */
    public static function sinAgenteIa(): array
    {
        return [
            'gerente' => [['gerente'], 'erp'],
            'admin' => [['admin'], 'erp'],
            // agente_ia en un token de persona (azp erp) no cuenta: solo los roles internos.
            'agente_ia desde el ERP' => [['agente_ia'], 'erp'],
        ];
    }

    /** @param list<string> $roles */
    #[DataProvider('sinAgenteIa')]
    public function test_sin_el_rol_agente_ia_responde_403(array $roles, string $azp): void
    {
        $this->inicializar($this->firmarConRoles($roles, $azp))
            ->assertForbidden()
            ->assertJsonPath('codigo', 'SIN_PERMISO');
    }

    public function test_con_agente_ia_responde_200_en_json_y_sin_sesion(): void
    {
        $respuesta = $this->inicializar()
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('MCP-Session-Id')
            ->assertHeader('X-Request-Id', self::REQUEST_ID)
            ->assertJsonPath('result.protocolVersion', self::VERSION_MCP)
            ->assertJsonPath('result.serverInfo.name', 'finanzas');

        // Solo herramientas: ni recursos ni prompts (501, criterio 3).
        $this->assertSame(['tools'], array_keys((array) $respuesta->json('result.capabilities')));
    }

    public function test_la_notificacion_initialized_responde_202(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], [
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$this->tokenAgente(),
            'MCP-Protocol-Version' => self::VERSION_MCP,
        ])->assertStatus(202);
    }

    public function test_get_y_delete_responden_405(): void
    {
        $encabezados = ['Authorization' => 'Bearer '.$this->tokenAgente()];
        $this->get('/mcp', $encabezados)->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->delete('/mcp', [], $encabezados)->assertStatus(405)->assertHeader('Allow', 'POST');
    }

    public function test_no_publica_recursos_ni_prompts(): void
    {
        foreach (['resources/list', 'prompts/list'] as $metodo) {
            $this->rpc($metodo)->assertJsonPath('error.code', -32601);
        }
    }
}
