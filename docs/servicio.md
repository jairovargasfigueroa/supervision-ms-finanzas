# Finanzas: qué hace

## Responsabilidades

- Registrar el contrato de cada obra (monto, plazo, anticipo, retención) y llevar su saldo.
- Reservar saldo para una planilla y liberarlo si la saga se compensa (pasos 2 y compensación).
- Emitir el cobro de una planilla aprobada y calcular su vencimiento (paso 4).
- Informar los cobros por vencer y vencidos.
- Capa 2 (201, cobranza automática): registrar el pago completo de un cobro y atender a los flujos de n8n (qué cobros necesitan recordatorio, la nota de cobro en PDF y la anotación de los correos enviados).

## Casos de uso

| Caso de uso | Quién | Etapa |
|---|---|---|
| Registrar contrato de la obra | Administración (parte de «Registrar obra») | Base |
| Reservar / liberar saldo | Saga (Obras) | Base |
| Emitir cobro | Saga (Obras) | Base |
| Consultar cobros de una obra | Administración, gerente | Base |
| Registrar pago de un cobro | Administración (`admin`) | Capa 2 (201) |
| Atender a la automatización de cobranza (recordatorios, nota de cobro en PDF, envíos) | n8n (rol `automatizacion`) | Capa 2 (201) |
| Herramientas MCP para los agentes | Servicio de IA | Capa 5 |

## Qué no hace

- No registra pagos parciales, ni anula o corrige un pago, ni pagos a proveedores (fuera del alcance). Tampoco envía correos: los envía n8n.
- No factura ante Impuestos Nacionales ni lleva contabilidad.
- No calcula la planilla (Obras); recibe el neto ya calculado.

## Reglas

Ver «Contrato», «Saga aprobar planilla» y «Cobranza (capa 2)» en `docs/reglas-negocio.md` de la carpeta general.
