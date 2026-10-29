# Finanzas: contratos

Fuente para este servicio. La copia general está en `docs/contratos/` de la carpeta que agrupa los proyectos. Errores en formato Problem Details con `codigo` (ver convenciones). Todas las rutas, salvo `/salud`, exigen token. JSON en `camelCase`.

## REST que expone (puerto 8083)

Las rutas van en la raíz (sin prefijo `/api`). Token: `Authorization: Bearer <token de Keycloak>`; se valida la firma con el JWKS del realm (`KEYCLOAK_ISSUER/protocol/openid-connect/certs`, en caché una hora y recargado si llega un `kid` desconocido), el vencimiento, el emisor `KEYCLOAK_ISSUER` y la audiencia `KEYCLOAK_AUDIENCIA` (`aud` texto o arreglo). Sin token o con uno inválido: `401 NO_AUTENTICADO`. Si Keycloak no responde al pedir el JWKS: `503 SERVICIO_NO_DISPONIBLE`. Una ruta que no existe (con token válido): `404 RUTA_NO_ENCONTRADA`. Toda ruta de la API exige token por defecto (`bootstrap/app.php`); solo `/salud` lo quita. Toda respuesta lleva `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` y `X-Request-Id` (el que llega o un UUID nuevo).

Rol (001): cada ruta indica abajo sus «Roles». Con token válido pero sin ninguno de esos roles: `403 SIN_PERMISO`. Cuentan solo los roles de la aplicación desde la que se inició sesión (`azp` del token): `portal` → solo `cliente`; `erp` o `app-obra` → solo `admin`, `residente`, `gerente`, `maestro`, `almacenero`; un cliente de servicio (`gateway`, `ia-agentes`, `n8n`) → los roles del token tal cual.

### GET /salud
Sin token · `200 {"estado":"ok"}` (no toca la base ni RabbitMQ)

### GET /contratos?obraId= · 006
Roles: `admin`, `gerente`.
`[{ "id", "obraId", "monto": 1250000.00, "plazoDias": 300, "anticipoPct": 10, "retencionPct": 7, "firmadoEn": "2026-10-20", "saldoDisponible": 1180000.00, "totalCobrado": 41881.80, "totalReservado": 0.00 }]`
(lista con 0 o 1 elemento).
- `obraId`: uuid, obligatorio. Una obra sin contrato: `[]`.
- Saldo (reglas del negocio, «Contrato»), calculado al leer: `totalCobrado` = suma de `montoNeto` de los cobros del contrato (`PENDIENTE` o `VENCIDO`); `totalReservado` = suma de las reservas `RESERVADA` (las `LIBERADA` y `CONSUMIDA` no cuentan: la consumida ya está en su cobro); `saldoDisponible` = `monto` − `totalCobrado` − `totalReservado`. Montos con 2 decimales.
- Errores: `400 DATOS_INVALIDOS` (`obraId` ausente o que no es uuid, con `errores[]`) · `403 SIN_PERMISO`.

### GET /cobros?obraId=&estado= · 006, 008
Roles: `admin`, `gerente`. Paginado (convenciones): `pagina`, `tamano`, `orden`, `direccion`; responde `{ items, total, pagina, tamano }` con orden por defecto `venceEn asc`. Cada elemento:
`[{ "id", "numero": "COB-2027-0003", "obraId", "planillaId", "montoNeto": 41881.80, "emitidoEn": "2027-04-02", "venceEn": "2027-05-02", "estado": "PENDIENTE" }]`
- `obraId` (uuid) y `estado` (`PENDIENTE` o `VENCIDO`): opcionales; sin `obraId`, los cobros de todas las obras. `obraId` es la obra del contrato del cobro.
- `orden`: `venceEn` o `emitidoEn`; `direccion`: `asc` o `desc` (desempata por `id`).
- Errores: `400 PAGINACION_INVALIDA` (`tamano` mayor a 100) · `400 DATOS_INVALIDOS` (otro parámetro mal formado: `obraId` que no es uuid, `estado` desconocido, `pagina` o `tamano` menor a 1, `orden` o `direccion` desconocidos; con `errores[]`) · `403 SIN_PERMISO`.

### GET /cobros/{id} · 008
Roles: `admin`, `gerente`. Mismo objeto que en la lista. (El gateway lo usa para el detalle del cobro de una planilla, solo para `admin` y `gerente`; el número del cobro lo guarda Obras en la planilla y lo ven todos los que ven la planilla.)

### GET /cobros/resumen · 009
Roles: `admin`, `gerente`.
`{ "vencidos": { "cantidad": 1, "monto": 41881.80 }, "porVencer30Dias": { "cantidad": 2, "monto": 90000.00 } }`

### Capa 2

Crear orden de compra y cuenta por pagar (las llama n8n). Se especifican en la capa 2.

## Eventos y comandos (RabbitMQ)

| Clave | Dirección | `datos` |
|---|---|---|
| `evento.obras.ObraRegistrada` | escucha | crea el contrato con `obraId` y `contrato{monto, plazoDias, anticipoPct, retencionPct, firmadoEn}` |
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

Todo mensaje va en el sobre común (convenciones), con `origen: "finanzas"` y, al responder, el `correlacionId` del mensaje recibido; se publica persistente, `content_type application/json` y `message_id` = `id` del sobre. Escucha la cola `finanzas`.

### ObraRegistrada (002)

- Valida el contrato. Si es válido crea `contrato` con `saldo_disponible = monto` y publica `ContratoRegistrado`. Si no, publica `ContratoRechazado` con `motivo`: uno o varios de estos textos, en este orden y unidos con «; »: «El monto del contrato debe ser mayor a 0», «El plazo del contrato debe ser mayor a 0 días», «El anticipo y la retención deben estar entre 0 % y 100 %», «El anticipo más la retención deben sumar menos de 100 %».
- `monto` y los porcentajes se aceptan como número (o texto numérico) y se redondean a 2 decimales; `plazoDias`, entero; `firmadoEn`, `AAAA-MM-DD`.
- Idempotente: un `id` ya procesado no hace nada, y si la obra ya tiene contrato (`obra_id` único) tampoco se crea otro ni se publica nada.
- Un mensaje con datos incompletos (sin `obraId` uuid, sin `contrato` o con campos que no tienen el tipo anterior) no se rechaza: va directo a `plataforma.fallidos`.

### Reintentos y fallidos

Igual en todos los servicios. El reintento y el envío a fallidos se publican por el exchange por defecto, con el cuerpo original sin tocar y el mismo `message_id`, y luego se confirma el original.

- Si procesar un mensaje falla, se vuelve a publicar en la cola `finanzas`. Al tercer intento fallido se publica en la cola `plataforma.fallidos`.
- Van directo a `plataforma.fallidos`, sin reintentos: un cuerpo que no es JSON, un sobre incompleto o con `version` distinta de 1, datos incompletos, y un `tipo` sin consumidor en la cola `finanzas` (por ahora todo lo que no es `ObraRegistrada`, por ejemplo los comandos de la 008).

| Encabezado | Valor |
|---|---|
| `x-intentos` | número de intentos ya hechos (1 en el primer reintento; 3 al ir a fallidos tras reintentar) |
| `x-error` | texto del error, sin datos sensibles |
| `x-cola-origen` | cola de donde salió: `finanzas` |
| `x-clave-origen` | clave de enrutamiento original, por ejemplo `evento.obras.ObraRegistrada` (al reintentar por el exchange por defecto se pierde) |

## Herramientas MCP (capa 5, solo lectura)

`cobros_por_vencer`. Se especifica en la capa 5. (`planillas_estado` es de Obras.)
