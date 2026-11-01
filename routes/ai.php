<?php

use App\Cobros\Infraestructura\Mcp\ServidorMcpFinanzas;
use Laravel\Mcp\Facades\Mcp;

// 501 · Servidor MCP de solo lectura: solo el rol agente_ia (service account de ia-agentes), con el mismo token que el
// REST. Lo carga laravel/mcp (no pasa por el grupo api). GET y DELETE /mcp responden 405 (sin estado).
Mcp::web('/mcp', ServidorMcpFinanzas::class)->middleware(['token', 'rol:agente_ia']);
