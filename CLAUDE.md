# Servicio Finanzas

Microservicio dueño de los contratos, las reservas de saldo y los cobros. En la capa 2 (201) registra el pago de los cobros y atiende a los flujos de cobranza de n8n (recordatorios, nota de cobro en PDF, envíos). Participa en la saga «aprobar planilla» reservando el saldo y emitiendo el cobro.

Parte de la «Plataforma de supervisión de obras». Si existe `../../CLAUDE.md` (carpeta que agrupa todos los proyectos), léelo primero: tiene las reglas transversales, el plan y los contratos generales.

**Estado:** fase 1: `GET /salud` y validación del token (toda ruta de la API exige token salvo `/salud`); 001: control por rol con el middleware `rol:...`; 002: mensajería con RabbitMQ (`mensajeria:publicar` y `mensajeria:consumir`, outbox, inbox, reintentos) y registro del contrato al recibir `ObraRegistrada`; 006 y 009: `GET /contratos` (saldo calculado al leer), `GET /cobros` y `GET /cobros/resumen`; 008: participa en la saga «aprobar planilla» (`ReservarSaldo`, `EmitirCobro`, `LiberarReserva`), `GET /cobros/{id}` y la tarea diaria `cobros:marcar-vencidos` (00:10 en La Paz, con `php artisan schedule:work`). 011: datos de prueba (`DatosPruebaSeeder` y `datos-prueba:vaciar`, solo desarrollo). 501: servidor MCP de solo lectura (`POST /mcp`, `cobros_por_vencer`). 201: `POST /cobros/{id}/pago` (admin, publica `CobroPagado`), `pagadoEn`, `comprobante` y `envios` en los cobros, y las rutas de n8n (rol `automatizacion`): `GET /cobros/recordatorios`, `POST /cobros/{id}/envios` y `POST /cobros/{id}/nota-cobro` (PDF). No implementes una funcionalidad sin su especificación aprobada en `docs/specs/`.

## Stack

- PHP 8.3+ · Laravel 13 (Composer). El `composer.lock` trae Symfony 8.1, que pide PHP 8.4.1 o más: la imagen y el CI usan PHP 8.4 (601)
- PostgreSQL (en `.env`: `DB_CONNECTION=pgsql`) · migraciones de Laravel
- RabbitMQ con `php-amqplib/php-amqplib` 3 · validación de tokens de Keycloak con `firebase/php-jwt` 7 (JWKS del realm)
- Nota de cobro en PDF con `barryvdh/laravel-dompdf` 3.1.2 (201); `EMPRESA_NOMBRE` en `.env`
- Pruebas con PHPUnit
- Puerto: `8083`

## Comandos

```sh
composer install
php artisan serve --port=8083   # levantar
php artisan migrate             # aplicar migraciones
php artisan mensajeria:consumir # consumir la cola finanzas (proceso largo)
php artisan mensajeria:publicar # publicar el outbox (proceso largo)
php artisan schedule:work       # tareas programadas (cobros vencidos a las 00:10 en La Paz)
php artisan test                # pruebas
vendor/bin/phpstan analyse      # análisis estático (larastan, nivel 6)
vendor/bin/pint --test          # formato
php artisan db:seed --class=DatosPruebaSeeder --force   # datos de prueba (011), con SEMILLA_FECHA=AAAA-MM-DD
php artisan datos-prueba:vaciar                          # vacía todas las tablas salvo migrations (011)
```

Imagen y CI (601, `docs/specs/601-contenedores-ci/` de `supervision-docs`):

```sh
docker build -t supervision-ms-finanzas .   # dos etapas: composer install --no-dev y FrankenPHP (PHP 8.4) con pdo_pgsql, bcmath, sockets y opcache; usuario 10001
docker run --rm -p 8083:8083 -e APP_KEY=base64:… -e DB_HOST=… -e DB_PASSWORD=… supervision-ms-finanzas            # API: migrate --force y frankenphp php-server sobre public/
docker run --rm -e APP_KEY=base64:… -e DB_HOST=… -e RABBITMQ_HOST=… supervision-ms-finanzas php artisan mensajeria:consumir   # la misma imagen, otro comando
```

- La imagen trae `APP_ENV=production`, `APP_DEBUG=false` y `LOG_CHANNEL=stderr` con `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` (registros en JSON); no lleva `.env`: toda la configuración entra por variables (las de `.env.example`, con `APP_KEY`). La API escucha en `PORT` (8083) y su `HEALTHCHECK` llama `GET /salud`; los procesos en segundo plano (`mensajeria:consumir`, `mensajeria:publicar`, `schedule:work`) corren con la misma imagen cambiando el comando (en el *compose*, sin *healthcheck*: no atienden HTTP).
- TLS: `DB_SSLMODE` (`prefer` por defecto; `require` en la nube: `verify-full` no, la imagen no trae el certificado raíz del proveedor) y `RABBITMQ_SSL=true` (con `RABBITMQ_PORT=5671`; valida el certificado y el nombre). Una CA adicional (la local del ensayo) se pasa sin tocar la imagen con un `.ini` montado en `/usr/local/etc/php/conf.d/` con `curl.cainfo` y `openssl.cafile`.
- La semilla desde la imagen: `php artisan db:seed --class=DatosPruebaSeeder --force` con `SEMILLA_DEMO=si` (en producción solo carga; `datos-prueba:vaciar` se niega siempre fuera de desarrollo).
- `.github/workflows/ci.yml`: en cada PR hacia `develop` o `main`, `composer install`, `pint --test`, `phpstan analyse`, `php artisan test` y la imagen sin publicar; en cada *push* a `main`, la imagen en GHCR (`ghcr.io/jairovargasfigueroa/supervision-ms-finanzas`, etiquetas `sha-<7>` y `main`).

Datos de prueba (011): los llama `infra/semilla` con `SEMILLA_FECHA` (la misma D para los tres servicios). Los datos están en `database/seeders/datos/finanzas.json`, copia de la sección 8 de `docs/datos-prueba.md` (si cambian, se cambian allí primero). 603: con `SEMILLA_HISTORIA=si` se suma `finanzas-historia.json` (obras 4 a 6, con cobros pagados; generado por `infra/semilla/historia/generar.mjs`, no se edita a mano). Solo en desarrollo: se niegan con `APP_ENV=production` o con una base que no es local (salvo la carga con `SEMILLA_DEMO=si`, 601; el vaciado nunca), y no escriben en el outbox. Las pruebas usan la base de pruebas (SQLite en memoria de `phpunit.xml`); nunca se corren contra `finanzas_db` desde una prueba.

Configuración local: copiar `.env.example` a `.env`, completar y ejecutar `php artisan key:generate`. **`.env` nunca se sube.**

## Arquitectura: DDD + Hexagonal

Estructura por contexto (`Contratos`, `Cobros`), dentro de `app/`:

```
app/<Contexto>/
  Dominio/          entidades, objetos de valor, reglas; sin Eloquent
  Aplicacion/       casos de uso y puertos (interfaces)
  Infraestructura/
    Http/           controladores y requests (adaptador de entrada)
    Persistencia/   modelos Eloquent y repositorios (adaptador de salida)
    Mensajeria/     publicación y consumo de RabbitMQ
```

- El dominio no depende de Laravel. Eloquent solo vive en `Infraestructura/Persistencia`.
- Agregados: `Contrato` (con sus reservas) y `Cobro` (con su pago y sus envíos, 201).

## Reglas del proyecto

- Lo que este servicio guarda está en `docs/datos.md`; lo que expone y escucha, en `docs/contratos.md`.
- Saldo disponible = monto − cobros emitidos − reservas vigentes. Una reserva que no alcanza hace fallar la saga.
- Un cobro por planilla (`planilla_id` único). Un envío de cada tipo por cobro (`envio_cobro`, único `cobro_id` + `tipo`, 201). Un cobro `PAGADO` no cambia más.
- Los eventos se escriben en `outbox_evento` en la misma transacción; los mensajes recibidos se registran en `mensaje_procesado`.

## Documentación

- `docs/estructura.md`: **librerías permitidas y estructura exacta de carpetas y archivos. Obligatorio leerlo antes de escribir código.**
- `docs/servicio.md`: responsabilidades, casos de uso y qué no hace.
- `docs/datos.md`: tablas.
- `docs/contratos.md`: REST, eventos y herramientas MCP.
- `docs/specs/`: especificaciones por funcionalidad (`requisitos.md`, `diseno.md`, `tareas.md`).

## Commits

Conventional Commits en español e imperativo (`feat(cobros): emite cobro al aprobar planilla`). Sin coautoría ni firmas de la IA.

Git Flow: ramas `feat/`, `fix/`, `docs/`… desde `develop`, que entran por *pull request*; nunca hagas *push* a `main` ni a `develop` (están protegidas). El agente crea y fusiona los PR (merge normal, sin squash) hacia `develop` cuando todas las pruebas pasan. Detalle y definición de terminado: `docs/flujo-trabajo.md` del repo `supervision-docs`.

---

Lo que sigue lo generó Laravel para agentes de IA. Se conserva tal cual.

<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>
