<?php

namespace App\Cobros\Infraestructura\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\Methods\Discover;
use Laravel\Mcp\Server\Methods\Initialize;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Methods\Ping;
use Laravel\Mcp\Server\Tool;

/**
 * Servidor MCP de solo lectura de Finanzas (501) en `POST /mcp` (`routes/ai.php`): solo lo llama el servicio de IA con
 * el token de `ia-agentes` (rol `agente_ia`). Sin estado y con respuestas JSON (cada petición arma el servidor; `GET` y
 * `DELETE` responden 405). Publica solo herramientas: ni recursos ni *prompts* (501, criterio 3).
 */
class ServidorMcpFinanzas extends Server
{
    protected string $name = 'finanzas';

    protected string $version = '0.1.0';

    protected string $instructions = 'Datos de cobros al cliente de la constructora, al día y de solo lectura.';

    /** @var array<string, array<string, bool>|\stdClass|string> */
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => ['listChanged' => false],
    ];

    /** @var array<int|string, Tool|class-string<Tool>|array<int, Tool|class-string<Tool>>> */
    protected array $tools = [];

    /**
     * Solo los métodos de herramientas y del saludo: `resources/*`, `prompts/*`, `completion/complete` y
     * `subscriptions/listen` responden «método no encontrado».
     *
     * @var array<string, class-string<Server\Contracts\Method>>
     */
    protected array $methods = [
        'tools/list' => ListTools::class,
        'tools/call' => CallTool::class,
        'server/discover' => Discover::class,
        'initialize' => Initialize::class,
        'ping' => Ping::class,
    ];
}
