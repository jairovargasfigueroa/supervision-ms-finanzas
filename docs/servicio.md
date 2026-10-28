# Finanzas: qué hace

## Responsabilidades

- Registrar el contrato de cada obra (monto, plazo, anticipo, retención) y llevar su saldo.
- Reservar saldo para una planilla y liberarlo si la saga se compensa (pasos 2 y compensación).
- Emitir el cobro de una planilla aprobada y calcular su vencimiento (paso 4).
- Informar los cobros por vencer y vencidos.
- Capa 2: proveedores, órdenes de compra generadas por «stock bajo» y cuentas por pagar al recibir el material.

## Casos de uso

| Caso de uso | Quién | Etapa |
|---|---|---|
| Registrar contrato de la obra | Administración (parte de «Registrar obra») | Base |
| Reservar / liberar saldo | Saga (Obras) | Base |
| Emitir cobro | Saga (Obras) | Base |
| Consultar cobros de una obra | Administración, gerente | Base |
| Crear orden de compra por stock bajo | n8n | Capa 2 |
| Crear cuenta por pagar al ingresar el material | n8n | Capa 2 |
| Herramientas MCP para los agentes | Servicio de IA | Capa 5 |

## Qué no hace

- No registra pagos de clientes ni a proveedores (fuera del alcance).
- No factura ante Impuestos Nacionales ni lleva contabilidad.
- No calcula la planilla (Obras); recibe el neto ya calculado.
- No da de alta proveedores desde pantalla: vienen como datos iniciales.

## Reglas

Ver «Contrato» y «Saga aprobar planilla» en `docs/reglas-negocio.md` de la carpeta general.
