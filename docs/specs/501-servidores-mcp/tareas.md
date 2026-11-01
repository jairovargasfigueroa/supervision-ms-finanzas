# 501 · Servidores MCP · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/501-servidores-mcp/` de la carpeta que agrupa los proyectos. Contratos: `docs/contratos.md` de este proyecto.

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [x] `composer require laravel/mcp:^1.1` (en Windows, `cmd` se come el `^`: revisar `composer.json`); ruta `Mcp::web('/mcp', …)` con los *middleware* de token y `rol:agente_ia`; aplicar «Cambios a contratos y datos» del diseño en `docs/contratos.md` y `docs/estructura.md`
- [x] Caso de uso `ConsultarCobrosPorVencer` (reutiliza el cálculo de 009) y herramienta `cobros_por_vencer` con validación de rangos, máximo 50 cobros con `truncado` (los totales cuentan todos los cobros) y anotaciones `readOnlyHint: true` y `openWorldHint: false`
- [x] Errores de herramienta (`ARGUMENTO_INVALIDO` sin consultar la base, `OBRA_NO_ENCONTRADA`, `ERROR_INTERNO`; en PostgreSQL, transacción de solo lectura con `statement_timeout` de 5 s) y registro de cada llamada con `X-Request-Id`
- [x] Pruebas: 401 / 403 / 200, `tools/list` exacto (esquemas y anotación), `diasParaVencer` y totales con fechas fijas, `truncado`, argumentos maliciosos, solo lectura
