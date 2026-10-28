# Finanzas: contratos

**Estado:** aquí va la parte de los contratos generales (`docs/contratos/` de la carpeta general) que toca a este servicio. Lo de abajo es lo conocido hasta ahora; los detalles se definen en el diseño de cada funcionalidad.

## REST que expone

| Operación | Quién llama | Etapa |
|---|---|---|
| (el contrato se crea por el evento `ObraRegistrada`, no por REST) | — | Base |
| Ver contrato y saldo | Gateway, Obras | Base |
| Listar cobros (por obra, por vencer) | Gateway | Base |
| Crear orden de compra | n8n | Capa 2 |
| Crear cuenta por pagar | n8n | Capa 2 |

## Eventos y comandos (RabbitMQ)

| Mensaje | Dirección | Notas |
|---|---|---|
| ObraRegistrada | escucha ← Obras | crea el contrato de la obra |
| ContratoRegistrado / ContratoRechazado | publica → Obras | respuesta al registro de la obra |
| Reservar saldo | escucha ← Obras | paso 2 de la saga |
| Saldo reservado / sin saldo | publica → Obras | |
| Emitir cobro | escucha ← Obras | paso 4 |
| Cobro emitido / fallo | publica → Obras | |
| Liberar reserva | escucha ← Obras | compensación |
| Cobro emitido | publica | capa 4 (reportes) |

## Herramientas MCP (capa 5, solo lectura)

`cobros_por_vencer`. (`planillas_estado` es de Obras, porque las planillas son de Obras.)
