# Finanzas

Microservicio de la Plataforma de supervisión de obras: contratos, saldo, cobros y, en la capa 2, órdenes de compra y cuentas por pagar.

PHP · Laravel · PostgreSQL.

```sh
cp .env.example .env   # completar los valores
composer install
php artisan key:generate
php artisan serve --port=8083
```

## Datos de prueba (solo desarrollo)

Carga los datos de Finanzas de [`docs/datos-prueba.md`](../../docs/datos-prueba.md) (funcionalidad 011): 3 contratos, 3 reservas `CONSUMIDA` y 3 cobros, con las fechas relativas a la fecha de carga D. Normalmente lo corre `infra/semilla/semilla.ps1` (o `semilla.sh`) junto con Obras y Materiales; también se puede correr solo. En Windows, desde PowerShell con el PHP de Herd.

```powershell
# Cargar (D = SEMILLA_FECHA; sin ella, hoy en La Paz). Idempotente: lo que ya existe por id no se toca.
$env:SEMILLA_FECHA = '2026-10-28'
php artisan db:seed --class=DatosPruebaSeeder --force

# Vaciar todas las tablas salvo migrations (para volver a cargar; `recargar` de infra pide confirmación antes)
php artisan datos-prueba:vaciar
```

En Bash: `SEMILLA_FECHA=2026-10-28 php artisan db:seed --class=DatosPruebaSeeder --force`.

- Calcula el número del cobro (`COB-<año>-<NNNN>` por año de emisión), su vencimiento y su estado (`VENCIDO` si vence antes de D). El saldo del contrato no se guarda: se ve en `GET /contratos`.
- No escribe en `outbox_evento` ni en `mensaje_procesado`: no publica eventos.
- Se niega (código de salida distinto de 0) con `APP_ENV=production` o si la base no está en `localhost`, `127.0.0.1`, `::1` o el contenedor `postgres` de infra. Excepción (601, datos de demostración): con `SEMILLA_DEMO=si` la carga corre igual; `datos-prueba:vaciar` se niega siempre fuera de desarrollo.
- Si una fila choca con otra que ya existe con otro id (por ejemplo, otro contrato de la misma obra), falla sin cargar nada: vaciar y volver a cargar.
- Los indicadores son exactos el día D; antes de una demostración, recargar con la fecha del día.

## Imagen (601)

```sh
docker build -t supervision-ms-finanzas .
docker run --rm -p 8083:8083 -e APP_KEY=base64:… -e DB_HOST=host.docker.internal -e DB_PORT=5433 -e DB_PASSWORD=… supervision-ms-finanzas
docker run --rm -e APP_KEY=base64:… -e DB_HOST=… -e RABBITMQ_HOST=… supervision-ms-finanzas php artisan mensajeria:consumir
```

La imagen trae `APP_ENV=production`, `APP_DEBUG=false` y `LOG_CHANNEL=stderr` y se configura solo con variables (las de `.env.example`). Su comando por defecto aplica las migraciones y levanta la API con FrankenPHP en `PORT` (8083); con otro comando corre `mensajeria:consumir`, `mensajeria:publicar` o `schedule:work`.

Documentación del servicio en [`docs/`](docs/) e instrucciones para el agente en [`CLAUDE.md`](CLAUDE.md).
