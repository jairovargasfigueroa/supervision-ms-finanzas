# Finanzas: datos (PostgreSQL)

Base propia: `finanzas_db`. Ningún otro servicio la lee. Las referencias a otros servicios («ref») son solo ids, sin clave foránea.

## Tablas del sistema base

### contrato
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| obra_id | uuid | ref Obras |
| monto | numeric | |
| plazo_dias | int | |
| anticipo_pct | numeric | |
| retencion_pct | numeric | |
| saldo_disponible | numeric | monto − cobros − reservas vigentes |
| firmado_en | date | |

### reserva_saldo
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| contrato_id | uuid | FK contrato |
| planilla_id | uuid | ref Obras |
| monto | numeric | |
| estado | varchar | `RESERVADA`, `LIBERADA`, `CONSUMIDA` |

### cobro
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| contrato_id | uuid | FK contrato |
| planilla_id | uuid | ref Obras, único |
| numero | varchar | |
| monto_neto | numeric | |
| emitido_en | date | |
| vence_en | date | |
| estado | varchar | `PENDIENTE`, `VENCIDO` |

### outbox_evento
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| tipo | varchar | |
| payload | jsonb | |
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
