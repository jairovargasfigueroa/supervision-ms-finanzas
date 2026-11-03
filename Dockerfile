# syntax=docker/dockerfile:1
# Imagen de Finanzas (601, docs/specs/601-contenedores-ci/ de supervision-docs). Una sola imagen para la API y los
# procesos en segundo plano: el comando por defecto aplica las migraciones y levanta la API con FrankenPHP (un solo
# proceso, sin nginx + php-fpm); con otro comando corre `php artisan mensajeria:consumir`, `mensajeria:publicar` o
# `schedule:work`. Sin .env: toda la configuración entra por variables al arrancar (las de .env.example, con APP_KEY).
# Imágenes base revisadas el 03/11/2026: dunglas/frankenphp 1.13.0 con PHP 8.4.26 (publicada el 11/10/2026; el
# composer.lock trae Symfony 8.1, que pide PHP 8.4.1 o más) y Composer 2.10.3 (27/08/2026).

FROM dunglas/frankenphp:1.13.0-php8.4.26-alpine AS base
# Extensiones que pide el servicio (las demás que usa Laravel ya vienen en la imagen) y php.ini de producción
RUN install-php-extensions pdo_pgsql bcmath sockets opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

FROM base AS construir
RUN install-php-extensions @composer-2.10.3
WORKDIR /app
# Primero las dependencias: su capa se reusa mientras composer.json y composer.lock no cambien
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
# Autoload optimizado; los scripts de Composer descubren los paquetes de Laravel (sin los de desarrollo)
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && rm -rf storage/logs/* bootstrap/cache/config.php bootstrap/cache/routes-*.php

FROM base AS final
LABEL org.opencontainers.image.source="https://github.com/jairovargasfigueroa/supervision-ms-finanzas" \
      org.opencontainers.image.description="Servicio Finanzas de la plataforma de supervisión de obras"
WORKDIR /app
COPY --from=construir /app /app
# Usuario sin privilegios (convención de la 601: numérico, 10001). Escribe solo en storage, bootstrap/cache y en los
# datos de Caddy. FrankenPHP no necesita su permiso para puertos bajos: escucha en 8083.
RUN addgroup -S -g 10001 finanzas \
    && adduser -S -D -H -u 10001 -G finanzas -h /app finanzas \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R 10001:10001 storage bootstrap/cache /data/caddy /config/caddy \
    && apk add --no-cache --virtual .herramientas-cap libcap-utils \
    && setcap -r /usr/local/bin/frankenphp \
    && apk del .herramientas-cap
# Modo producción (los tres se pueden cambiar al arrancar), la base del servicio y el puerto de convenciones.md
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    APP_TIMEZONE=America/La_Paz \
    DB_CONNECTION=pgsql \
    PORT=8083
EXPOSE 8083
USER 10001:10001
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD wget -q -O /dev/null "http://127.0.0.1:${PORT}/salud" || exit 1
CMD ["sh", "-c", "php artisan migrate --force && exec frankenphp php-server --root public/ --listen \":${PORT}\""]
