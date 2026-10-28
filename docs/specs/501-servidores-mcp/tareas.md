# 501 · Servidores MCP · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/501-servidores-mcp/` de la carpeta que agrupa los proyectos. Contratos: `docs/contratos.md` de este proyecto.

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [ ] `composer require laravel/mcp` (1.x); ruta `Mcp::web('/mcp', …)` con los *middleware* de token y `rol:agente_ia`
- [ ] Caso de uso `ConsultarCobrosPorVencer` (reutiliza el cálculo de 009) y herramienta `cobros_por_vencer` con validación de rangos
- [ ] Errores de herramienta y registro de cada llamada con `X-Request-Id`
- [ ] Pruebas: 401 / 403 / 200, `tools/list` exacto, `diasParaVencer` y totales con fechas fijas, argumentos maliciosos, solo lectura
