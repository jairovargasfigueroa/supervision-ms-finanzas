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
| firmado_en | date | |

El saldo disponible no se guarda: se calcula al leer (monto − cobros emitidos − reservas `RESERVADA`), en `GET /contratos` (006) y al reservar (008). La columna `saldo_disponible` de la 002 estaba duplicada y la quitó la 008. Al reservar, la saga bloquea la fila del contrato (`SELECT … FOR UPDATE`) antes de calcular el saldo, así dos reservas a la vez no gastan el mismo saldo.

### reserva_saldo
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| contrato_id | uuid | FK contrato |
| planilla_id | uuid | ref Obras |
| monto | numeric(14,2) | |
| estado | varchar(20) | `RESERVADA`, `LIBERADA`, `CONSUMIDA` |

Índice único parcial `reserva_saldo_planilla_vigente_unique` en `planilla_id` con `estado = 'RESERVADA'`: una sola reserva vigente por planilla (las liberadas y consumidas no cuentan; una planilla observada puede volver a aprobarse). Se crea en la 006 (solo lectura: total reservado y saldo); la escribe la saga de la 008.

### cobro
| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| contrato_id | uuid | FK contrato |
| planilla_id | uuid | ref Obras, único |
| numero | varchar | único; `COB-<año>-<correlativo de 4 dígitos>`, correlativo global por año de `emitido_en` |
| monto_neto | numeric(14,2) | |
| emitido_en | date | fecha de emisión en `America/La_Paz` |
| vence_en | date | `emitido_en` + 30 días |
| estado | varchar(20) | `PENDIENTE`, `VENCIDO` (la tarea diaria de las 00:10 en La Paz pasa a `VENCIDO` los `PENDIENTE` con `vence_en` anterior a hoy), `PAGADO` (201: al registrar el pago, desde `PENDIENTE` o `VENCIDO`; un `PAGADO` no cambia más) |
| pagado_en | date | 201: fecha de pago (La Paz); null si no está pagado |
| comprobante | varchar(50) | 201: n.º de comprobante, opcional (null si no se indicó) |
| pago_registrado_por | uuid | 201: ref Keycloak (`sub` de quien registró el pago) |
| pago_registrado_en | timestamptz | 201: cuándo se registró el pago |

Correlativo del número: no hay contador aparte; al emitir, la saga toma el mayor correlativo de los cobros de ese año y suma 1 (en PostgreSQL, con un bloqueo de transacción `pg_advisory_xact_lock` por año para que dos emisiones a la vez no tomen el mismo; el índice único de `numero` lo respalda). Así, cargar cobros con sus números (semilla de la 011) ya deja la serie en el último número usado. Se crea en la 006 (solo lectura: `GET /cobros` y total cobrado); la escribe la saga de la 008. En la 201, `admin` registra el pago (`POST /cobros/{id}/pago`): el saldo del contrato no cambia, porque «cobrado» son los cobros emitidos en cualquier estado.

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

## Tablas de la capa 2 (cobranza automática, 201)

### envio_cobro
Correos que n8n envió por cada cobro; los anota n8n después de enviarlos (`POST /cobros/{id}/envios`). Da la idempotencia de los flujos: n8n no guarda estado propio.

| Campo | Tipo | Notas |
|---|---|---|
| id | uuid | PK |
| cobro_id | uuid | FK cobro |
| tipo | varchar(30) | `NOTA_COBRO`, `RECORDATORIO_POR_VENCER`, `RECORDATORIO_VENCIDO`, `CONSTANCIA_PAGO` |
| destinatario | varchar(254) | correo del cliente al que se envió |
| enviado_en | timestamptz | |
| ejecucion_n8n | varchar(64) | id de la ejecución de n8n, para buscarla en su panel; opcional |

Único (`cobro_id`, `tipo`): cada cobro tiene a lo sumo un envío de cada tipo.

(Las tablas `proveedor`, `orden_compra` y `cuenta_por_pagar` eran de la compra automática por stock bajo, reemplazada por la cobranza automática: no existen.)

## Relaciones

- contrato 1 — 0..* reserva_saldo · contrato 1 — 0..* cobro
- cobro 1 — 0..4 envio_cobro (uno por tipo)
