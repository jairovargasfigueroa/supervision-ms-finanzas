# Finanzas: contratos

**Estado: pendiente.** Aquí va la parte de los contratos generales (`docs/contratos/` de la carpeta general) que toca a este servicio. Lo de abajo es lo conocido hasta ahora; los detalles se definen en el diseño de cada funcionalidad.

## REST que expone

| Operación | Quién llama | Etapa |
|---|---|---|
| Registrar contrato de una obra | Gateway | Base |
| Ver contrato y saldo | Gateway, Obras | Base |
| Listar cobros (por obra, por vencer) | Gateway | Base |
| Crear orden de compra | n8n | Capa 2 |
| Crear cuenta por pagar | n8n | Capa 2 |

## Eventos y comandos (RabbitMQ)

| Mensaje | Dirección | Notas |
|---|---|---|
| Reservar saldo | escucha ← Obras | paso 2 de la saga |
| Saldo reservado / sin saldo | publica → Obras | |
| Emitir cobro | escucha ← Obras | paso 4 |
| Cobro emitido / fallo | publica → Obras | |
| Liberar reserva | escucha ← Obras | compensación |
| Cobro emitido | publica | capa 4 (reportes) |

## Herramientas MCP (capa 5, solo lectura)

`cobros_por_vencer`, `planillas_estado` **[por confirmar: este último puede ser de Obras]**.
