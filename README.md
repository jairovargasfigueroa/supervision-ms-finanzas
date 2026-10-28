# Finanzas

Microservicio de la Plataforma de supervisión de obras: contratos, saldo, cobros y, en la capa 2, órdenes de compra y cuentas por pagar.

PHP · Laravel · PostgreSQL.

```sh
cp .env.example .env   # completar los valores
composer install
php artisan key:generate
php artisan serve --port=8083
```

Documentación del servicio en [`docs/`](docs/) e instrucciones para el agente en [`CLAUDE.md`](CLAUDE.md).
