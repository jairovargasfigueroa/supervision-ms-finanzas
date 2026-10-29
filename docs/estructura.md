# Finanzas: estructura y librerías

Obligatorio para todo el código de este servicio. Si algo no encaja, propón el cambio antes de salirte de esta estructura.

## Librerías

Laravel 13 (Form Requests, API Resources, Eloquent solo en infraestructura, scheduler), `php-amqplib/php-amqplib` 3, `firebase/php-jwt` 7 (JWKS), `dedoc/scramble` 0.13 (OpenAPI en `/docs/api`, solo local), `larastan/larastan` 3 (nivel 6), Laravel Pint, PHPUnit 12. Ejecutar siempre con el PHP de Herd (PowerShell).

## Árbol (DDD + Hexagonal)

```
app/
  Compartido/
    Dominio/          ReglaNegocioException.php, Dinero.php (redondeo a 2)
    Infraestructura/
      Http/           ManejadorProblemDetails.php (registrado en bootstrap/app.php), MiddlewareRequestId.php
      Auth/           ValidadorTokenKeycloak.php, MiddlewareToken.php, MiddlewareRol.php (alias `rol`), UsuarioActual.php,
                      RolesEfectivos.php (función pura: roles que cuentan según azp)
      Mensajeria/     ConexionRabbit.php, Sobre.php, PublicadorOutbox.php, ConsumidorBase.php, OutboxEvento.php (modelo), MensajeProcesado.php (modelo)
  Contratos/                    # un contexto por carpeta: Contratos, Cobros, Compras (capa 2)
    Dominio/          Contrato.php, ReservaSaldo.php, EstadoReserva.php
    Aplicacion/
      Puertos/        ContratoRepositorio.php, PublicadorEventos.php
      CasosUso/       RegistrarContrato.php, ReservarSaldo.php, LiberarReserva.php, ConsultarContrato.php
    Infraestructura/
      Persistencia/   ContratoModelo.php, ReservaSaldoModelo.php, ContratoRepositorioEloquent.php
      Http/           ContratoController.php, ContratoResource.php, ConsultarContratosRequest.php
      Mensajeria/     ObraRegistradaManejador.php, ReservarSaldoManejador.php
  Console/Commands/   MensajeriaPublicar.php, MensajeriaConsumir.php, MarcarCobrosVencidos.php
  Providers/          FinanzasServiceProvider.php (enlaza puertos con implementaciones y crea ValidadorTokenKeycloak)
config/services.php   keycloak.emisor (KEYCLOAK_ISSUER) y keycloak.audiencia (KEYCLOAK_AUDIENCIA)
database/migrations/  una migración por tabla y funcionalidad
routes/api.php        rutas en español según docs/contratos.md, en la raíz (apiPrefix vacío); /salud sin token y el resto
                      en el grupo `token` (alias de MiddlewareToken), que cierra con una ruta de reserva que responde 404
tests/
  Utilidades/TokensPrueba.php  (trait: claves RSA de prueba, JWKS servido con Http::fake, firmar() y firmarConRoles(roles, azp))
  Feature/Compartido/SaludTest.php, AutenticacionTest.php (token: 401/503/404, con TokensPrueba)
  Unit/Contratos/Dominio/ContratoTest.php
  Feature/Contratos/ConsultarContratosTest.php
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

## Ejemplo de punta a punta

Mensaje `comando.finanzas.ReservarSaldo` → `MensajeriaConsumir` → `ReservarSaldoManejador` (inbox) → `ReservarSaldo::ejecutar` → `Contrato::reservar(monto)` → repositorio + outbox `SaldoReservado` o `SaldoInsuficiente`, en una transacción.

## Pruebas

- Unitarias del dominio sin base de datos.
- Feature con PostgreSQL de prueba (`finanzas_test`) y `RefreshDatabase`; tokens firmados con clave de prueba.
- `php artisan test`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`.
