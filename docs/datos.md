# Finanzas: datos (PostgreSQL)

Base propia: `finanzas_db`. Ningún otro servicio la lee. Las referencias a otros servicios («ref») son solo ids, sin clave foránea.

## Tablas del sistema base

### contrato
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| obra_id | uuid | ref Obras, único (un contrato por obra) |
| monto | numeric(14,2) | > 0 |
| plazo_dias | int | > 0 |
| anticipo_pct | numeric(5,2) | 0 a 100 |
| retencion_pct | numeric(5,2) | 0 a 100; anticipo + retención < 100 |
| saldo_disponible | numeric(14,2) | monto − cobros − reservas vigentes; al crearse, igual al monto. `GET /contratos` (006) no lo lee: calcula el saldo con `cobro` y `reserva_saldo` |
| firmado_en | date | |

### reserva_saldo
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| contrato_id | uuid | FK contrato |
| planilla_id | uuid | ref Obras |
| monto | numeric(14,2) | |
| estado | varchar(20) | `RESERVADA`, `LIBERADA`, `CONSUMIDA` |

Se crea en la 006 (solo lectura: total reservado y saldo); la escribe la saga de la 008.

### cobro
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| contrato_id | uuid | FK contrato |
| planilla_id | uuid | ref Obras, único |
| numero | varchar | |
| monto_neto | numeric(14,2) | |
| emitido_en | date | |
| vence_en | date | |
| estado | varchar(20) | `PENDIENTE`, `VENCIDO` |

Se crea en la 006 (solo lectura: `GET /cobros` y total cobrado); la escribe la saga de la 008.

### outbox_evento
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK; es el `id` del sobre (RabbitMQ lo recibe como `message_id`) |
| tipo | varchar | clave de enrutamiento, por ejemplo `evento.finanzas.ContratoRegistrado` |
| payload | jsonb | sobre completo del mensaje |
| creado_en | timestamptz | se publican en este orden |
| publicado_en | timestamptz | nulo hasta publicarse |

### mensaje_procesado
| Campo | Tipo | Notas |
|---|---|---|
| id_mensaje | uuid | PK |
| procesado_en | timestamptz | |

## Tablas de la capa 2 (automatización)

### proveedor
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| nombre | varchar | |
| correo | varchar | |
| telefono | varchar | |

### orden_compra
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| numero | varchar | |
| obra_id | uuid | ref Obras |
| proveedor_id | uuid | FK proveedor |
| material_id | varchar | ref Materiales |
| cantidad | numeric | |
| precio_unitario | numeric | |
| monto | numeric | |
| estado | varchar | `ENVIADA`, `RECIBIDA` |
| evento_origen_id | uuid | único; evita duplicar la orden si el evento llega dos veces |
| enviada_en | timestamptz | |

### cuenta_por_pagar
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| orden_compra_id | uuid | FK orden_compra, único |
| proveedor_id | uuid | FK proveedor |
| ingreso_id | uuid | ref Materiales, único |
| monto | numeric | |
| emitida_en | date | |
| vence_en | date | |

## Relaciones

- contrato 1 — 0..* reserva_saldo · contrato 1 — 0..* cobro
- proveedor 1 — 0..* orden_compra · orden_compra 1 — 0..1 cuenta_por_pagar
- proveedor 1 — 0..* cuenta_por_pagar
