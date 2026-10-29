# Servicio Finanzas

Microservicio dueño de los contratos, las reservas de saldo y los cobros. En la capa 2 también lleva proveedores, órdenes de compra y cuentas por pagar. Participa en la saga «aprobar planilla» reservando el saldo y emitiendo el cobro.

Parte de la «Plataforma de supervisión de obras». Si existe `../../CLAUDE.md` (carpeta que agrupa todos los proyectos), léelo primero: tiene las reglas transversales, el plan y los contratos generales.

**Estado:** esqueleto. No implementes una funcionalidad sin su especificación aprobada en `docs/specs/`.

## Stack

- PHP 8.3 · Laravel 13 (Composer)
- PostgreSQL (en `.env`: `DB_CONNECTION=pgsql`) · migraciones de Laravel
- RabbitMQ con `php-amqplib/php-amqplib` 3 · validación de tokens de Keycloak con `firebase/php-jwt` 7 (JWKS del realm)
- Pruebas con PHPUnit
- Puerto: `8083`

## Comandos

```sh
composer install
php artisan serve --port=8083   # levantar
php artisan migrate             # aplicar migraciones
php artisan test                # pruebas
```

Configuración local: copiar `.env.example` a `.env`, completar y ejecutar `php artisan key:generate`. **`.env` nunca se sube.**

## Arquitectura: DDD + Hexagonal

Estructura prevista por contexto (`Contratos`, `Cobros`, `Compras`), dentro de `app/`:

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
- Agregados: `Contrato` (con sus reservas y cobros) y, en la capa 2, `OrdenCompra`.

## Reglas del proyecto

- Lo que este servicio guarda está en `docs/datos.md`; lo que expone y escucha, en `docs/contratos.md`.
- Saldo disponible = monto − cobros emitidos − reservas vigentes. Una reserva que no alcanza hace fallar la saga.
- Un cobro por planilla (`planilla_id` único). Una orden de compra por evento de stock bajo (`evento_origen_id` único).
- Los eventos se escriben en `outbox_evento` en la misma transacción; los mensajes recibidos se registran en `mensaje_procesado`.

## Documentación

- `docs/estructura.md`: **librerías permitidas y estructura exacta de carpetas y archivos. Obligatorio leerlo antes de escribir código.**
- `docs/servicio.md`: responsabilidades, casos de uso y qué no hace.
- `docs/datos.md`: tablas.
- `docs/contratos.md`: REST, eventos y herramientas MCP.
- `docs/specs/`: especificaciones por funcionalidad (`requisitos.md`, `diseno.md`, `tareas.md`).

## Commits

Conventional Commits en español e imperativo (`feat(cobros): emite cobro al aprobar planilla`). Sin coautoría ni firmas de la IA.

Git Flow: ramas `feat/`, `fix/`, `docs/`… desde `develop`, que entran por *pull request*; nunca hagas *push* a `main` ni a `develop` (están protegidas). El agente crea y fusiona los PR (squash) hacia `develop` cuando todas las pruebas pasan. Detalle y definición de terminado: `docs/flujo-trabajo.md` del repo `supervision-docs`.

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
