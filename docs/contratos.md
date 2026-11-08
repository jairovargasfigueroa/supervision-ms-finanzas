# Finanzas: contratos

Fuente para este servicio. La copia general está en `docs/contratos/` de la carpeta que agrupa los proyectos. Errores en formato Problem Details con `codigo` (ver convenciones). Todas las rutas, salvo `/salud`, exigen token. JSON en `camelCase`.

## REST que expone (puerto 8083)

Las rutas van en la raíz (sin prefijo `/api`). Token: `Authorization: Bearer <token de Keycloak>`; se valida la firma con el JWKS del realm (`KEYCLOAK_ISSUER/protocol/openid-connect/certs`, en caché una hora y recargado si llega un `kid` desconocido), el vencimiento, el emisor `KEYCLOAK_ISSUER` y la audiencia `KEYCLOAK_AUDIENCIA` (`aud` texto o arreglo). Sin token o con uno inválido: `401 NO_AUTENTICADO`. Si Keycloak no responde al pedir el JWKS: `503 SERVICIO_NO_DISPONIBLE`. Una ruta que no existe (con token válido): `404 RUTA_NO_ENCONTRADA`. Toda ruta de la API exige token por defecto (`bootstrap/app.php`); solo `/salud` lo quita. Toda respuesta lleva `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` y `X-Request-Id` (el que llega o un UUID nuevo).

Rol (001): cada ruta indica abajo sus «Roles». Con token válido pero sin ninguno de esos roles: `403 SIN_PERMISO`. Cuentan solo los roles de la aplicación desde la que se inició sesión (`azp` del token): `portal` → solo `cliente`; `erp` o `app-obra` → solo `admin`, `residente`, `gerente`, `maestro`, `almacenero`; un cliente de servicio (`gateway`, `ia-agentes`, `n8n`) → los roles del token tal cual. El *service account* de `n8n` (201) tiene `automatizacion` (solo las rutas de la 201 que llama n8n) y `gerente` (para leer `GET /cobros/{id}` como los demás).

### GET /salud
Sin token · `200 {"estado":"ok"}` (no toca la base ni RabbitMQ)

### GET /contratos?obraId= · 006
Roles: `admin`, `gerente`.
`[{ "id", "obraId", "monto": 1250000.00, "plazoDias": 300, "anticipoPct": 10, "retencionPct": 7, "firmadoEn": "2026-10-20", "saldoDisponible": 1180000.00, "totalCobrado": 41881.80, "totalReservado": 0.00 }]`
(lista con 0 o 1 elemento).
- `obraId`: uuid, obligatorio. Una obra sin contrato: `[]`.
- Saldo (reglas del negocio, «Contrato»), calculado al leer: `totalCobrado` = suma de `montoNeto` de los cobros emitidos del contrato (`PENDIENTE`, `VENCIDO` y, desde la 201, `PAGADO`: pagar no cambia el saldo); `totalReservado` = suma de las reservas `RESERVADA` (las `LIBERADA` y `CONSUMIDA` no cuentan: la consumida ya está en su cobro); `saldoDisponible` = `monto` − `totalCobrado` − `totalReservado`. Montos con 2 decimales.
- Errores: `400 DATOS_INVALIDOS` (`obraId` ausente o que no es uuid, con `errores[]`) · `403 SIN_PERMISO`.

### GET /cobros?obraId=&estado= · 006, 008, 201
Roles: `admin`, `gerente`. Quién llama: gateway (ERP) y el servicio de IA en la carga inicial (401), con el token de `ia-reportes` (rol `gerente`). Paginado (convenciones): `pagina`, `tamano`, `orden`, `direccion`; responde `{ items, total, pagina, tamano }` con orden por defecto `venceEn asc`. Cada elemento:
`[{ "id", "numero": "COB-2027-0003", "obraId", "planillaId", "montoNeto": 41881.80, "emitidoEn": "2027-04-02", "venceEn": "2027-05-02", "estado": "PENDIENTE", "pagadoEn": null, "comprobante": null, "envios": [{ "tipo": "NOTA_COBRO", "destinatario": "cliente1@correo.test", "enviadoEn": "2027-04-02T12:00:41Z" }] }]`
- 201: `pagadoEn` (`AAAA-MM-DD`) y `comprobante` son `null` si el cobro no está pagado (o se pagó sin comprobante); `envios` son los correos que anotó n8n (`NOTA_COBRO`, `RECORDATORIO_POR_VENCER`, `RECORDATORIO_VENCIDO`, `CONSTANCIA_PAGO`), ordenados por `enviadoEn` (UTC, ISO 8601); `[]` si no hay.
- `obraId` (uuid) y `estado` (`PENDIENTE`, `VENCIDO` o `PAGADO`): opcionales; sin `obraId`, los cobros de todas las obras. `obraId` es la obra del contrato del cobro.
- `orden`: `venceEn` o `emitidoEn`; `direccion`: `asc` o `desc` (desempata por `id`).
- Errores: `400 PAGINACION_INVALIDA` (`tamano` mayor a 100) · `400 DATOS_INVALIDOS` (otro parámetro mal formado: `obraId` que no es uuid, `estado` desconocido, `pagina` o `tamano` menor a 1, `orden` o `direccion` desconocidos; con `errores[]`) · `403 SIN_PERMISO`.

### GET /cobros/{id} · 008, 201
Roles: `admin`, `gerente` (n8n entra con su `gerente`). Mismo objeto que en la lista (con `pagadoEn`, `comprobante` y `envios`). (El gateway lo usa para el detalle del cobro de una planilla, solo para `admin` y `gerente`; el número del cobro lo guarda Obras en la planilla y lo ven todos los que ven la planilla.)
- `id`: uuid. La ruta se registra después de `GET /cobros/resumen` y solo acepta un uuid, así «resumen» no se toma como id.
- Errores: `404` si no existe o el `id` no es un uuid (el contrato no define un código propio: responde el genérico `RUTA_NO_ENCONTRADA`) · `403 SIN_PERMISO`.

### GET /cobros/resumen · 009
Roles: `admin`, `gerente`.
`{ "vencidos": { "cantidad": 1, "monto": 41881.80 }, "porVencer30Dias": { "cantidad": 2, "monto": 90000.00 } }`
- Cobros de todas las obras, sin parámetros. «Hoy» es la fecha actual en `America/La_Paz` (convenciones).
- Los cobros `PAGADO` (201) no cuentan en ningún grupo.
- `vencidos`: cobros `VENCIDO` y también los `PENDIENTE` con `venceEn` anterior a hoy (un cobro `PENDIENTE` pasa a `VENCIDO` al día siguiente de su vencimiento, reglas del negocio; se cuentan aunque todavía no se haya cambiado su estado guardado).
- `porVencer30Dias`: cobros `PENDIENTE` con `venceEn` entre hoy y hoy + 30 días, ambos incluidos (el que vence hoy todavía no está vencido). Un cobro cuenta en un solo grupo.
- `cantidad`: número de cobros; `monto`: suma de su `montoNeto` con 2 decimales (`0` si no hay).
- Errores: `403 SIN_PERMISO`.

### Tarea programada · 008

`php artisan cobros:marcar-vencidos`, programada con el scheduler de Laravel (`routes/console.php`) cada día a las 00:10 hora de La Paz: los cobros `PENDIENTE` con `vence_en` anterior a hoy (fecha en `America/La_Paz`) pasan a `VENCIDO`. Idempotente. Un cobro `PAGADO` (201) no se vence. Para que corra hace falta el scheduler: `php artisan schedule:work` en desarrollo o `php artisan schedule:run` cada minuto en el despliegue.

### Capa 2 · Cobranza automática (201)

Rol técnico `automatizacion` (*service account* del cliente confidencial `n8n`): solo entra en `GET /cobros/recordatorios`, `POST /cobros/{id}/envios` y `POST /cobros/{id}/nota-cobro`; ninguna otra ruta lo acepta. «Hoy» es la fecha actual en `America/La_Paz`. En las rutas `POST` con `{id}`, un id que no es uuid se trata como un cobro que no existe (`404 COBRO_NO_ENCONTRADO`).

#### POST /cobros/{id}/pago
Roles: `admin`. Quién llama: el gateway (`registrarPagoCobro`), con el token del usuario.
`{ "pagadoEn": "2027-04-28", "comprobante": "TRF-883412" }`
1. `pagadoEn` obligatorio (`AAAA-MM-DD`); `comprobante` opcional, de 1 a 50 caracteres (vacío o solo espacios se guarda como `null`; se quitan los espacios de los extremos).
2. En una transacción, bloquea la fila del cobro (`FOR UPDATE`). Si no existe → `404 COBRO_NO_ENCONTRADO`; si ya está `PAGADO` → `409 COBRO_YA_PAGADO` (no se paga dos veces ni se deshace).
3. `pagadoEn` posterior a hoy (La Paz) o anterior a `emitidoEn` → `422 FECHA_PAGO_INVALIDA` (hoy y el día de emisión valen).
4. Guarda `PAGADO`, `pagado_en`, `comprobante`, `pago_registrado_por` (`sub` del token) y `pago_registrado_en`, y deja `CobroPagado` en el outbox en la misma transacción. Se paga el cobro completo; el saldo del contrato no cambia.

Responde `200` con el cobro (mismo objeto que `GET /cobros/{id}`). Errores: `400 DATOS_INVALIDOS` (con `errores[]`) · `401` · `403 SIN_PERMISO` · `404 COBRO_NO_ENCONTRADO` · `409 COBRO_YA_PAGADO` · `422 FECHA_PAGO_INVALIDA`.

#### GET /cobros/recordatorios
Roles: `automatizacion`. Quién llama: n8n («Recordar cobros», todos los días a las 08:00 de La Paz). Se registra antes de `GET /cobros/{id}`.
```json
{
  "hoy": "2027-04-29",
  "porVencer": [{ "id", "numero", "obraId", "planillaId", "montoNeto", "emitidoEn", "venceEn", "estado", "diasParaVencer": 3 }],
  "vencidos":  [{ "id", "numero", "obraId", "planillaId", "montoNeto", "emitidoEn", "venceEn", "estado", "diasVencido": 1 }]
}
```
- `porVencer`: `PENDIENTE` con `venceEn` entre hoy y hoy + 3 días (ambos incluidos) y sin envío `RECORDATORIO_POR_VENCER`. `diasParaVencer` = `venceEn − hoy` (0 si vence hoy).
- `vencidos`: `VENCIDO`, o `PENDIENTE` con `venceEn` anterior a hoy, sin envío `RECORDATORIO_VENCIDO`. `diasVencido` = `hoy − venceEn`.
- Un `PAGADO` no aparece. Un cobro aparece en un solo grupo. Orden por `venceEn` y `numero`. Sin paginar. `estado` es el guardado.
- Errores: `401` · `403 SIN_PERMISO`.

#### POST /cobros/{id}/envios
Roles: `automatizacion`. n8n anota cada correo después de enviarlo.
`{ "tipo": "NOTA_COBRO", "destinatario": "cliente1@correo.test", "ejecucionN8n": "1532" }`
- `tipo`: `NOTA_COBRO`, `RECORDATORIO_POR_VENCER`, `RECORDATORIO_VENCIDO` o `CONSTANCIA_PAGO`; `destinatario`: obligatorio, hasta 254 caracteres; `ejecucionN8n`: opcional, hasta 64 caracteres.
- Si el cobro ya tiene un envío de ese `tipo` → `200` con el existente (no lo cambia). Si no → lo crea con `enviadoEn` = ahora → `201`. Idempotente por (`cobro_id`, `tipo`), también con dos llamadas a la vez (bloquea la fila del cobro).
- Respuesta: el envío, `{ "tipo", "destinatario", "enviadoEn" }` (el mismo objeto que en `envios[]` del cobro).
- Errores: `400 DATOS_INVALIDOS` (tipo desconocido, destinatario vacío; con `errores[]`) · `401` · `403 SIN_PERMISO` · `404 COBRO_NO_ENCONTRADO`.

#### POST /cobros/{id}/nota-cobro
Roles: `automatizacion`. n8n le pasa los datos de la planilla (los leyó de Obras) y recibe el PDF de la nota de cobro para adjuntarlo al correo. No se guarda: se genera en cada llamada.
```json
{
  "cliente": { "nombre": "Roberto Justiniano Paz" },
  "obra": { "id": "uuid", "nombre": "Edificio Residencial Las Palmeras" },
  "planilla": {
    "id": "uuid", "numero": 3, "periodo": "2026-09",
    "lineas": [{ "codigo": "P-03", "nombre": "Hormigón armado en columnas", "unidad": "m3", "cantidadPeriodo": 40, "precioUnitario": 3365.00, "monto": 134600.00 }],
    "subtotal": 397770.00, "descuentoAnticipo": 39777.00, "retencion": 27843.90, "neto": 330149.10,
    "anticipoPct": 10, "retencionPct": 7
  }
}
```
1. Todos los campos son obligatorios (`obra.id` y `planilla.id` uuid, `planilla.numero` entero ≥ 1, `periodo` `AAAA-MM`, `lineas` arreglo, montos, cantidades y porcentajes numéricos) → si no, `400 DATOS_INVALIDOS` con `errores[]`.
2. Cobro inexistente → `404 COBRO_NO_ENCONTRADO`.
3. Coherencia → `422 NOTA_COBRO_INCONSISTENTE` si `planilla.id` no es la del cobro, si `obra.id` no es la del contrato del cobro, si `neto` difiere de `montoNeto` del cobro (más de 0,005), o si los totales no cuadran (suma de los `monto` de las líneas = `subtotal`; `subtotal − descuentoAnticipo − retencion = neto`; montos comparados a 2 decimales).
4. Responde `200` `application/pdf` con `Content-Disposition: attachment; filename="nota-cobro-COB-2027-0003.pdf"`. Contenido: empresa (`EMPRESA_NOMBRE`, por defecto «Constructora Andina»), cliente, obra, planilla N.º y periodo («septiembre 2026»), las líneas con `cantidadPeriodo` > 0 (código, partida, unidad, cantidad del periodo, precio unitario y monto), subtotal, descuento por anticipo, retención, neto a cobrar, número de cobro, fecha de emisión y vencimiento, y la leyenda «Este documento no es una factura». Montos como `Bs 330.149,10` y fechas `dd/mm/aaaa`. Librería: `barryvdh/laravel-dompdf` 3.1.2.
- Errores: `400 DATOS_INVALIDOS` · `401` · `403 SIN_PERMISO` · `404 COBRO_NO_ENCONTRADO` · `422 NOTA_COBRO_INCONSISTENTE`.

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
| `evento.finanzas.CobroEmitido` | publica | `planillaId, cobroId, numero, venceEn` (lo escuchan Obras, IA y n8n, 201) |
| `evento.finanzas.CobroFallido` | publica | `planillaId, motivo` |
| `comando.finanzas.LiberarReserva` | escucha | `planillaId, reservaId` |
| `evento.finanzas.ReservaLiberada` | publica | `planillaId, reservaId` |
| `evento.finanzas.CobroPagado` | publica (201) | `cobroId, planillaId, obraId, numero, montoNeto, pagadoEn, comprobante` (lo escuchan n8n e IA) |

Todo mensaje va en el sobre común (convenciones), con `origen: "finanzas"` y, al responder, el `correlacionId` del mensaje recibido (`CobroPagado`, que nace de una petición REST, lleva `correlacionId` = `cobroId`); se publica persistente, `content_type application/json` y `message_id` = `id` del sobre. Escucha la cola `finanzas`.

### ObraRegistrada (002)

- Valida el contrato. Si es válido crea `contrato` (su saldo disponible empieza igual al monto: se calcula al leer) y publica `ContratoRegistrado`. Si no, publica `ContratoRechazado` con `motivo`: uno o varios de estos textos, en este orden y unidos con «; »: «El monto del contrato debe ser mayor a 0», «El plazo del contrato debe ser mayor a 0 días», «El anticipo y la retención deben estar entre 0 % y 100 %», «El anticipo más la retención deben sumar menos de 100 %».
- `monto` y los porcentajes se aceptan como número (o texto numérico) y se redondean a 2 decimales; `plazoDias`, entero; `firmadoEn`, `AAAA-MM-DD`.
- Idempotente: un `id` ya procesado no hace nada, y si la obra ya tiene contrato (`obra_id` único) tampoco se crea otro ni se publica nada.
- Un mensaje con datos incompletos (sin `obraId` uuid, sin `contrato` o con campos que no tienen el tipo anterior) no se rechaza: va directo a `plataforma.fallidos`.

### Saga «aprobar planilla» (008)

Finanzas es participante: Obras orquesta y le envía comandos a la cola `finanzas`; cada respuesta lleva el `correlacionId` del comando (el id de la saga en Obras). Los tres comandos son idempotentes por el `id` del mensaje (`mensaje_procesado`) y además por las reglas de abajo. Datos que faltan o mal formados (`planillaId`, `obraId` o `reservaId` que no son uuid; `monto` que no es número o no es mayor a 0) van directo a `plataforma.fallidos`. `monto` se acepta como número o texto numérico y se redondea a 2 decimales.

- **`ReservarSaldo {planillaId, obraId, monto}`**: en una transacción, bloquea la fila del contrato de la obra (`FOR UPDATE`) y calcula su saldo disponible (monto − cobros − reservas `RESERVADA`). Si la planilla ya tiene una reserva `RESERVADA`, responde de nuevo `SaldoReservado` con esa misma (no crea otra). Si el saldo alcanza (saldo justo incluido), crea la `reserva_saldo` `RESERVADA` y responde `SaldoReservado {planillaId, reservaId}`; si no, `SaldoInsuficiente {planillaId, motivo: "Saldo del contrato insuficiente"}`. Una obra sin contrato no debería llegar (solo una obra con contrato tiene planillas): se trata como error, se reintenta y termina en fallidos (en Obras, el paso 2 vence).
- **`EmitirCobro {planillaId, reservaId, monto}`**: bloquea la reserva. Si la planilla ya tiene cobro, responde `CobroEmitido` con el existente. Si la reserva no está `RESERVADA` (liberada o consumida), `CobroFallido {planillaId, motivo: "No se pudo emitir el cobro: la reserva de saldo ya no está vigente"}`. Si no, crea el `cobro` con `numero` `COB-<año>-<NNNN>` (siguiente correlativo del año de emisión), `emitido_en` = hoy en `America/La_Paz`, `vence_en` = `emitido_en` + 30 días, `PENDIENTE` y `monto_neto` = `monto`; marca la reserva `CONSUMIDA` y responde `CobroEmitido {planillaId, cobroId, numero, venceEn}` (`venceEn` en `AAAA-MM-DD`). Ante cualquier otro error al emitir (la reserva no existe o es de otra planilla, el `monto` no es el reservado, falla al guardar), `CobroFallido` con «No se pudo emitir el cobro por un error en Finanzas»; el error se registra en el log.
- **`LiberarReserva {planillaId, reservaId}`**: si la reserva de esa planilla está `RESERVADA`, la marca `LIBERADA` (el monto vuelve al saldo) y publica `ReservaLiberada {planillaId, reservaId}` (nadie lo escucha; queda en el log). Si ya estaba liberada o consumida, o no existe para esa planilla, no cambia nada ni publica nada.

### CobroPagado (201)

- Se publica al registrar el pago (`POST /cobros/{id}/pago`), por el outbox en la misma transacción. `montoNeto` con 2 decimales, `pagadoEn` `AAAA-MM-DD`, `comprobante` texto o `null`. `obraId` es la obra del contrato del cobro.

### Reintentos y fallidos

Igual en todos los servicios. El reintento y el envío a fallidos se publican por el exchange por defecto, con el cuerpo original sin tocar y el mismo `message_id`, y luego se confirma el original.

- Si procesar un mensaje falla, se vuelve a publicar en la cola `finanzas`. Al tercer intento fallido se publica en la cola `plataforma.fallidos`.
- Van directo a `plataforma.fallidos`, sin reintentos: un cuerpo que no es JSON, un sobre incompleto o con `version` distinta de 1, datos incompletos, y un `tipo` sin consumidor en la cola `finanzas` (hoy, todo lo que no es `ObraRegistrada`, `ReservarSaldo`, `EmitirCobro` ni `LiberarReserva`).

| Encabezado | Valor |
|---|---|
| `x-intentos` | número de intentos ya hechos (1 en el primer reintento; 3 al ir a fallidos tras reintentar) |
| `x-error` | texto del error, sin datos sensibles |
| `x-cola-origen` | cola de donde salió: `finanzas` |
| `x-clave-origen` | clave de enrutamiento original, por ejemplo `evento.obras.ObraRegistrada` (al reintentar por el exchange por defecto se pierde) |

## Herramientas MCP (capa 5, solo lectura) · 501

Ruta: `POST /mcp` (MCP, HTTP «streamable», sin estado, respuestas JSON; versión común 2025-11-25) · Rol: `agente_ia` (*service account* de `ia-agentes`) · Solo lo llama el servicio de IA

- Mismo token que el REST (`token` y `rol:agente_ia`): sin token o inválido `401 NO_AUTENTICADO`; con token válido sin `agente_ia` (también `gerente` o `admin`) `403 SIN_PERMISO`. `GET` y `DELETE /mcp` responden `405` (no hay sesión ni flujo SSE). Ningún endpoint REST acepta `agente_ia`. (`planillas_estado` es de Obras.)
- `tools/list` publica solo `cobros_por_vencer`, con `inputSchema` y `outputSchema` (JSON Schema con `additionalProperties: false`) y `annotations: { readOnlyHint: true, openWorldHint: false }`. Sin recursos ni *prompts*.
- Resultado: `structuredContent` con la salida y el mismo JSON como texto en `content`.
- Errores de herramienta (resultado con `isError: true` y texto JSON `{"codigo", "mensaje"}`): `ARGUMENTO_INVALIDO` (no cumple el esquema: tipo, formato, rango o campo de más; no consulta la base), `OBRA_NO_ENCONTRADA` (la obra no tiene contrato en Finanzas), `ERROR_INTERNO` (fallo de la base). Una herramienta que no existe es un error del protocolo.
- Registro: una línea JSON por llamada con `herramienta`, `argumentos`, `filas`, `duracionMs` y `requestId` (`X-Request-Id`); nunca el token.

### `cobros_por_vencer {dias?, incluirVencidos?, obraId?}`

«Devuelve los cobros pendientes que vencen en los próximos días y, si se pide, los vencidos, al día, con totales.»

- Entrada: `dias` (entero 1 a 90; por defecto 15), `incluirVencidos` (booleano; por defecto `true`), `obraId` (uuid, opcional; la obra de su contrato).
- Salida: `{ "hoy": "2027-04-20", "cobros": [{ "cobroId", "numero", "obraId", "planillaId", "montoNeto", "emitidoEn", "venceEn", "estado", "diasParaVencer" }], "totales": { "vencidos": { "cantidad", "monto" }, "porVencer": { "cantidad", "monto" } }, "truncado" }`.
- `hoy` en `America/La_Paz`. Por vencer: `PENDIENTE` con `venceEn` entre hoy y hoy + `dias`, ambos incluidos. Vencidos (si `incluirVencidos`): `VENCIDO`, o `PENDIENTE` con `venceEn` anterior a hoy (la misma regla de `GET /cobros/resumen`); si `incluirVencidos` es `false`, `totales.vencidos` es `{ cantidad: 0, monto: 0 }`. `estado` es el guardado (como en `GET /cobros`). `diasParaVencer` = `venceEn − hoy` (negativo si venció).
- Orden por `venceEn` (y `numero`); máximo 50 cobros, `truncado` si hay más. Los totales cuentan todos los cobros que cumplen el filtro (no solo los 50); `monto` con 2 decimales.
- Finanzas no guarda el nombre de la obra; el agente lo obtiene de `planillas_estado` o `avance_obra` (Obras).
