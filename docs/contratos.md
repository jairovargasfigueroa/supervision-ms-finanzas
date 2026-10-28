# Finanzas: contratos

Fuente para este servicio. La copia general está en `docs/contratos/` de la carpeta que agrupa los proyectos. Errores en formato Problem Details con `codigo` (ver convenciones). Todas las rutas, salvo `/salud`, exigen token. JSON en `camelCase`.

## REST que expone (puerto 8083)

### GET /salud
Sin token · `200 {"estado":"ok"}`

### GET /contratos?obraId= · 006
Roles: `admin`, `gerente`.
`[{ "id", "obraId", "monto": 1250000.00, "plazoDias": 300, "anticipoPct": 10, "retencionPct": 7, "firmadoEn": "2026-10-20", "saldoDisponible": 1180000.00, "totalCobrado": 41881.80, "totalReservado": 0.00 }]`
(lista con 0 o 1 elemento).

### GET /cobros?obraId=&estado= · 006, 008
Roles: `admin`, `gerente`.
`[{ "id", "numero": "COB-2027-0003", "obraId", "planillaId", "montoNeto": 41881.80, "emitidoEn": "2027-04-02", "venceEn": "2027-05-02", "estado": "PENDIENTE" }]`

### GET /cobros/{id} · 008
Roles: `admin`, `gerente`, `residente`. Mismo objeto que en la lista. (El gateway lo usa para mostrar el número de cobro de una planilla.)

### GET /cobros/resumen · 009
Roles: `admin`, `gerente`.
`{ "vencidos": { "cantidad": 1, "monto": 41881.80 }, "porVencer30Dias": { "cantidad": 2, "monto": 90000.00 } }`

### Capa 2

Crear orden de compra y cuenta por pagar (las llama n8n). Se especifican en la capa 2.

## Eventos y comandos (RabbitMQ)

| Clave | Dirección | `datos` |
|---|---|---|
| `evento.obras.ObraRegistrada` | escucha | crea el contrato con `obraId` y `contrato{…}` |
| `evento.finanzas.ContratoRegistrado` | publica | `obraId, contratoId` |
| `evento.finanzas.ContratoRechazado` | publica | `obraId, motivo` |
| `comando.finanzas.ReservarSaldo` | escucha | `planillaId, obraId, monto` |
| `evento.finanzas.SaldoReservado` | publica | `planillaId, reservaId` |
| `evento.finanzas.SaldoInsuficiente` | publica | `planillaId, motivo` |
| `comando.finanzas.EmitirCobro` | escucha | `planillaId, reservaId, monto` |
| `evento.finanzas.CobroEmitido` | publica | `planillaId, cobroId, numero, venceEn` (también lo usa la capa 4) |
| `evento.finanzas.CobroFallido` | publica | `planillaId, motivo` |
| `comando.finanzas.LiberarReserva` | escucha | `planillaId, reservaId` |
| `evento.finanzas.ReservaLiberada` | publica | `planillaId, reservaId` |

## Herramientas MCP (capa 5, solo lectura)

`cobros_por_vencer`. Se especifica en la capa 5. (`planillas_estado` es de Obras.)
