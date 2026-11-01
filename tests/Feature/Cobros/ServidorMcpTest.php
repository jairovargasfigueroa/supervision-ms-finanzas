<?php

namespace Tests\Feature\Cobros;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Contratos\Infraestructura\Persistencia\ContratoModelo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    private const CONTRATO_ID = 'c0a10000-0000-4000-8000-000000000001';

    private const OTRO_CONTRATO_ID = 'c0a10000-0000-4000-8000-000000000002';

    private const OBRA_ID = '0b1a0000-0000-4000-8000-000000000001';

    private const OTRA_OBRA_ID = '0b1a0000-0000-4000-8000-000000000002';

    private const OBRA_INEXISTENTE = '0b1a0000-0000-4000-8000-000000000099';

    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
        foreach ([[self::CONTRATO_ID, self::OBRA_ID], [self::OTRO_CONTRATO_ID, self::OTRA_OBRA_ID]] as [$id, $obraId]) {
            ContratoModelo::query()->create([
                'id' => $id,
                'obra_id' => $obraId,
                'monto' => '3000000.00',
                'plazo_dias' => 300,
                'anticipo_pct' => '10.00',
                'retencion_pct' => '7.00',
                'firmado_en' => '2026-06-01',
            ]);
        }
        // 02:00 UTC del 30 de octubre: en La Paz todavía es el 29 (22:00). Hoy = 2026-10-29.
        CarbonImmutable::setTestNow('2026-10-30T02:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function cobro(string $venceEn, string $estado, string $monto, string $contratoId = self::CONTRATO_ID): string
    {
        $id = (string) Str::uuid();
        CobroModelo::query()->create([
            'id' => $id,
            'contrato_id' => $contratoId,
            'planilla_id' => (string) Str::uuid(),
            'numero' => sprintf('COB-2026-%04d', ++$this->secuencia),
            'monto_neto' => $monto,
            'emitido_en' => '2026-09-01',
            'vence_en' => $venceEn,
            'estado' => $estado,
        ]);

        return $id;
    }

    /** Los cobros de los bordes: vencido, pendiente ya vencido, hoy, hoy + 15 (incluido) y hoy + 16 (fuera). */
    private function cobrosDeBordes(): void
    {
        $this->cobro('2026-10-19', 'VENCIDO', '290134.80');
        $this->cobro('2026-10-25', 'PENDIENTE', '1000.00', self::OTRO_CONTRATO_ID);
        $this->cobro('2026-10-29', 'PENDIENTE', '2000.50');
        $this->cobro('2026-11-13', 'PENDIENTE', '405865.20', self::OTRO_CONTRATO_ID);
        $this->cobro('2026-11-14', 'PENDIENTE', '365764.40');
    }

    /**
     * `tools/call` con el token de agente_ia: el resultado de la herramienta.
     *
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private function llamar(array $argumentos = [], string $herramienta = 'cobros_por_vencer'): array
    {
        $resultado = $this->rpc('tools/call', ['name' => $herramienta, 'arguments' => (object) $argumentos])
            ->assertOk()
            ->assertJsonMissingPath('error')
            ->json('result');
        $this->assertIsArray($resultado);

        return $resultado;
    }

    /**
     * Salida de una llamada correcta; comprueba que el texto es el mismo JSON que `structuredContent`.
     *
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    private function salida(array $argumentos = []): array
    {
        $resultado = $this->llamar($argumentos);
        $this->assertFalse($resultado['isError']);
        $this->assertSame($resultado['structuredContent'], json_decode($resultado['content'][0]['text'], true));

        return $resultado['structuredContent'];
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

    public function test_por_defecto_15_dias_con_vencidos_ordenados_por_vencimiento(): void
    {
        $this->cobrosDeBordes();

        $salida = $this->salida();

        $this->assertSame('2026-10-29', $salida['hoy']);
        $this->assertSame(
            [['2026-10-19', 'VENCIDO', -10], ['2026-10-25', 'PENDIENTE', -4], ['2026-10-29', 'PENDIENTE', 0], ['2026-11-13', 'PENDIENTE', 15]],
            array_map(fn (array $c) => [$c['venceEn'], $c['estado'], $c['diasParaVencer']], $salida['cobros']),
        );
        $this->assertEquals([
            'vencidos' => ['cantidad' => 2, 'monto' => 291134.8],
            'porVencer' => ['cantidad' => 2, 'monto' => 407865.7],
        ], $salida['totales']);
        $this->assertFalse($salida['truncado']);
    }

    public function test_cada_cobro_igual_que_en_get_cobros(): void
    {
        $this->cobrosDeBordes();

        $rest = collect($this->getJson('/cobros?tamano=100', [
            'Authorization' => 'Bearer '.$this->firmarConRoles(['gerente'], 'erp'),
        ])->assertOk()->json('items'))->keyBy('id');

        foreach ($this->salida()['cobros'] as $cobro) {
            $esperado = $rest[$cobro['cobroId']];
            $this->assertEquals(
                [$esperado['numero'], $esperado['obraId'], $esperado['planillaId'], $esperado['montoNeto'], $esperado['emitidoEn'], $esperado['venceEn'], $esperado['estado']],
                [$cobro['numero'], $cobro['obraId'], $cobro['planillaId'], $cobro['montoNeto'], $cobro['emitidoEn'], $cobro['venceEn'], $cobro['estado']],
            );
        }
    }

    public function test_con_30_dias_los_totales_son_los_de_get_cobros_resumen(): void
    {
        $this->cobrosDeBordes();
        $this->cobro('2026-11-28', 'PENDIENTE', '10.00');
        $this->cobro('2026-11-29', 'PENDIENTE', '20.00');

        $resumen = $this->getJson('/cobros/resumen', [
            'Authorization' => 'Bearer '.$this->firmarConRoles(['gerente'], 'erp'),
        ])->assertOk()->json();

        $totales = $this->salida(['dias' => 30])['totales'];
        $this->assertEquals($resumen['vencidos'], $totales['vencidos']);
        $this->assertEquals($resumen['porVencer30Dias'], $totales['porVencer']);
    }

    public function test_sin_vencidos_y_por_obra(): void
    {
        $this->cobrosDeBordes();

        $sinVencidos = $this->salida(['incluirVencidos' => false, 'dias' => 90]);
        $this->assertSame(['2026-10-29', '2026-11-13', '2026-11-14'], array_column($sinVencidos['cobros'], 'venceEn'));
        $this->assertEquals(['cantidad' => 0, 'monto' => 0], $sinVencidos['totales']['vencidos']);

        $deLaOtra = $this->salida(['obraId' => self::OTRA_OBRA_ID]);
        $this->assertSame([self::OTRA_OBRA_ID], array_values(array_unique(array_column($deLaOtra['cobros'], 'obraId'))));
        $this->assertEquals([
            'vencidos' => ['cantidad' => 1, 'monto' => 1000],
            'porVencer' => ['cantidad' => 1, 'monto' => 405865.2],
        ], $deLaOtra['totales']);
    }

    public function test_mas_de_50_cobros_devuelve_50_con_truncado_y_los_totales_cuentan_todos(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->cobro('2026-11-0'.(1 + $i % 9), 'PENDIENTE', '100.00');
        }

        $salida = $this->salida();

        $this->assertCount(50, $salida['cobros']);
        $this->assertTrue($salida['truncado']);
        $this->assertEquals(['cantidad' => 55, 'monto' => 5500], $salida['totales']['porVencer']);
        $vencimientos = array_column($salida['cobros'], 'venceEn');
        $ordenados = $vencimientos;
        sort($ordenados);
        $this->assertSame($ordenados, $vencimientos);
    }

    public function test_sin_cobros(): void
    {
        $this->assertEquals([
            'hoy' => '2026-10-29',
            'cobros' => [],
            'totales' => ['vencidos' => ['cantidad' => 0, 'monto' => 0], 'porVencer' => ['cantidad' => 0, 'monto' => 0]],
            'truncado' => false,
        ], $this->salida());
    }
}
