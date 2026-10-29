# Finanzas: estructura y librerías

Obligatorio para todo el código de este servicio. Si algo no encaja, propón el cambio antes de salirte de esta estructura.

## Librerías

Laravel 13 (Form Requests, API Resources, Eloquent solo en infraestructura, scheduler), `php-amqplib/php-amqplib` 3, `firebase/php-jwt` 7 (JWKS), `dedoc/scramble` 0.13 (OpenAPI en `/docs/api`, solo local), `larastan/larastan` 3 (nivel 6), Laravel Pint, PHPUnit 12. Ejecutar siempre con el PHP de Herd (PowerShell).

## Árbol (DDD + Hexagonal)

```
app/
  Compartido/
    Dominio/          ReglaNegocioException.php, Dinero.php (redondeo a 2, sumar, restar), CriteriosPagina.php (tamano > 100 →
                      PAGINACION_INVALIDA), Pagina.php ({ items, total, pagina, tamano })
    Infraestructura/
      Http/           ManejadorProblemDetails.php (registrado en bootstrap/app.php; ReglaNegocioException con su código y estado,
                      ValidationException → 400 DATOS_INVALIDOS con errores[]), MiddlewareRequestId.php
      Auth/           ValidadorTokenKeycloak.php, MiddlewareToken.php, MiddlewareRol.php (alias `rol`), UsuarioActual.php,
                      RolesEfectivos.php (función pura: roles que cuentan según azp)
      Mensajeria/     CanalMensajes.php (puerto del broker: publicar, consumir, cerrar), ConexionRabbit.php (lo implementa con
                      php-amqplib), Sobre.php, PublicadorOutbox.php, ConsumidorBase.php, OutboxEvento.php (modelo),
                      MensajeProcesado.php (modelo), MensajeInvalidoException.php (va directo a fallidos)
  Contratos/                    # un contexto por carpeta: Contratos, Cobros, Compras (capa 2)
    Dominio/          Contrato.php, ReservaSaldo.php (008), EstadoReserva.php, SaldoContrato.php (monto − cobrado − reservado, 006)
    Aplicacion/
      ContratoConsultado.php (lo que lee GET /contratos: datos + SaldoContrato)
      Puertos/        ContratoRepositorio.php (consultarPorObra: suma cobros y reservas vigentes), PublicadorEventos.php
      CasosUso/       RegistrarContrato.php (+ ResultadoRegistrarContrato.php), ReservarSaldo.php, LiberarReserva.php (008),
                      ConsultarContrato.php (006)
    Infraestructura/
      Persistencia/   ContratoModelo.php, ReservaSaldoModelo.php, ContratoRepositorioEloquent.php
      Http/           ContratoController.php, ContratoResource.php, ConsultarContratosRequest.php
      Mensajeria/     PublicadorEventosOutbox.php (implementa PublicadorEventos: `evento.finanzas.<tipo>` al outbox),
                      ObraRegistradaManejador.php, ReservarSaldoManejador.php
  Cobros/                       # cobros al cliente: los lee la 006, los emite la 008
    Dominio/          EstadoCobro.php (PENDIENTE, VENCIDO), CorteResumen.php (009: hoy en America/La_Paz y hoy + 30 días)
    Aplicacion/
      CobroConsultado.php (lo que lee GET /cobros, con el obraId del contrato), ResumenCobros.php y TotalCobros.php (GET /cobros/resumen, 009)
      Puertos/        CobroRepositorio.php (listar paginado; ORDENES; resumir: vencidos y por vencer con un CorteResumen)
      CasosUso/       ListarCobros.php (006), ResumirCobros.php (009)
    Infraestructura/
      Persistencia/   CobroModelo.php, CobroRepositorioEloquent.php (une contrato para el obraId; resumir con COUNT y SUM, whereDate)
      Http/           CobroController.php, CobroResource.php, ListarCobrosRequest.php, ResumenCobrosResource.php (009)
  Console/Commands/   MensajeriaPublicar.php, MensajeriaConsumir.php, MarcarCobrosVencidos.php
  Providers/          FinanzasServiceProvider.php (enlaza puertos con implementaciones y crea ValidadorTokenKeycloak)
config/services.php   keycloak.emisor (KEYCLOAK_ISSUER) y keycloak.audiencia (KEYCLOAK_AUDIENCIA)
database/migrations/  una migración por tabla y funcionalidad (008: quita `contrato.saldo_disponible`, índice parcial de la
                      reserva vigente y `cobro.numero` único)
routes/api.php        rutas en español según docs/contratos.md, en la raíz (apiPrefix vacío); /salud sin token y el resto
                      en el grupo `token` (alias de MiddlewareToken), que cierra con una ruta de reserva que responde 404
tests/
  Utilidades/TokensPrueba.php  (trait: claves RSA de prueba, JWKS servido con Http::fake, firmar() y firmarConRoles(roles, azp))
  Utilidades/CanalMensajesFalso.php  (doble en memoria de CanalMensajes: guarda lo publicado y simula las colas)
  Feature/Mensajeria/PublicadorOutboxTest.php, ConsumidorTest.php (outbox, reintentos con x-intentos, fallidos e inbox)
  Feature/Mensajeria/ObraRegistradaTest.php (002: válido, inválido, repetido, misma obra, datos incompletos)
  Feature/Compartido/SaludTest.php, AutenticacionTest.php (token: 401/503/404, con TokensPrueba)
  Feature/Compartido/RolTest.php  (rol:...: 401/403/200 por rol y azp, con rutas registradas solo en la prueba)
  Unit/Compartido/RolesEfectivosTest.php (roles que cuentan según azp y UsuarioActual)
  Unit/Compartido/CriteriosPaginaTest.php
  Unit/Contratos/Dominio/ContratoTest.php, SaldoContratoTest.php
  Unit/Cobros/Dominio/CorteResumenTest.php (009: fecha de hoy en La Paz y hoy + 30)
  Feature/Contratos/ConsultarContratosTest.php (006: saldo con y sin cobros y reservas, 400, 403 por rol)
  Feature/Contratos/RestriccionesSagaTest.php (008: una reserva vigente por planilla, cobro y número únicos, sin columna de saldo)
  Feature/Cobros/ListarCobrosTest.php (006: filtros, paginación, orden, 400, PAGINACION_INVALIDA, 403 por rol)
  Feature/Cobros/ResumirCobrosTest.php (009: datos de prueba, pendiente ya vencido, bordes de hoy y hoy + 30 en La Paz, 403 por rol)
  Feature/Mensajeria/ReservarSaldoTest.php
```

## Reglas

- `Dominio` no usa Eloquent, facades ni helpers de Laravel. `Aplicacion` depende de `Dominio` y de interfaces en `Puertos`.
- Los casos de uso son clases con un método `ejecutar(...)`, envueltos en `DB::transaction` cuando escriben.
- Controladores delgados: Form Request valida, el caso de uso decide, API Resource da forma al JSON (`camelCase`).
- Cada manejador de mensajes es idempotente (inbox `mensaje_procesado`) y publica su respuesta por el outbox.
- Las reglas del negocio lanzan `ReglaNegocioException(codigo, mensaje, status)`; el manejador global responde Problem Details.
- Autorización por rol (001): cada ruta declara sus roles con `->middleware('rol:admin,gerente')` (tabla de roles de `docs/contratos.md`). `MiddlewareRol` corre después de `MiddlewareToken` (grupo `api`), lee el token de `$request->attributes->get('token')` con `UsuarioActual::desde($request)` y responde `403 SIN_PERMISO` si el usuario no tiene ninguno. Cuentan solo los roles de `RolesEfectivos::de(roles, azp)` (convenciones, «Usuario con varios roles»): `azp = portal` → solo `cliente`; `erp` o `app-obra` → solo los internos; otro cliente (de servicio) → los del token tal cual. Toda regla que decida por rol usa `UsuarioActual::rolesEfectivos()`, nunca los roles del token directo. Una ruta sin `rol:` exige solo token válido; `/salud` no pasa por el token ni por el rol.
- Clases y métodos en español; sufijos técnicos permitidos (`Controller`, `Resource`, `Request`, `Modelo`, `Manejador`, `Test`).

## Mensajería (002)

- `php artisan mensajeria:publicar`: proceso largo; cada segundo envía al exchange `plataforma` las filas pendientes de `outbox_evento` (clave = `tipo`, cuerpo = `payload`, `message_id` = `id`, persistente, `application/json`) y les pone `publicado_en`. `--una-vez` publica y termina.
- `php artisan mensajeria:consumir`: proceso largo; consume la cola `finanzas` y pasa cada sobre al manejador de su `tipo` (lista en `MensajeriaConsumir::manejadores()`). El manejador extiende `ConsumidorBase`, que registra el `id` en `mensaje_procesado` en la misma transacción (repetido: no hace nada). Si procesar falla, vuelve a publicar el mensaje en la cola `finanzas` (exchange por defecto, cuerpo sin tocar) con `x-intentos` + 1 y confirma el original; al tercer fallo lo publica en `plataforma.fallidos`. Un mensaje inválido (`MensajeInvalidoException`: no es JSON, sobre incompleto o de otra versión, datos incompletos o `tipo` sin manejador) va directo a `plataforma.fallidos`. Encabezados `x-intentos`, `x-error`, `x-cola-origen` y `x-clave-origen` (docs/contratos.md). Si se cae la conexión, reconecta cada 5 s.
- El exchange y las colas los declara infra; aquí no se redeclaran (la cola se comprueba con una declaración pasiva).
- Las pruebas no usan RabbitMQ: enlazan `CanalMensajes` con `CanalMensajesFalso` (`tests/Utilidades`).

## Ejemplo de punta a punta

Mensaje `comando.finanzas.ReservarSaldo` → `MensajeriaConsumir` → `ReservarSaldoManejador` (inbox) → `ReservarSaldo::ejecutar` → `Contrato::reservar(monto)` → repositorio + outbox `SaldoReservado` o `SaldoInsuficiente`, en una transacción.

## Pruebas

- Unitarias del dominio sin base de datos.
- Feature con PostgreSQL de prueba (`finanzas_test`) y `RefreshDatabase`; tokens firmados con clave de prueba.
- `php artisan test`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`.
