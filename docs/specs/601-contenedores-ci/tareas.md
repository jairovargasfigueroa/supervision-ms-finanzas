# 601 · Contenedores y CI/CD · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/601-contenedores-ci/` de la carpeta que agrupa los proyectos. Contratos: `docs/contratos.md` de este proyecto.

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [ ] `.env.example` para PostgreSQL (`DB_CONNECTION=pgsql`, `DB_*`) y variables propuestas `DB_SSLMODE`, `KEYCLOAK_URL`, `KEYCLOAK_REALM`, `AUDIENCIA`, `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_SSL`; `LOG_CHANNEL=stderr` en producción
- [ ] `Dockerfile` (`composer:2` para dependencias, `dunglas/frankenphp:1-php8.3` con `pdo_pgsql`, `bcmath`, `sockets`, `opcache`; usuario sin privilegios) y `.dockerignore` (sin `vendor` ni `.env`)
- [ ] Comandos de la misma imagen para la API (con `php artisan migrate --force` previo) y para el proceso en segundo plano (consumidores de la saga y de la 201, outbox y `schedule:work`)
- [ ] `.github/workflows/ci-cd.yml`: `php artisan test` con PostgreSQL de servicio, imagen en GHCR, despliegue de `finanzas-api` y `finanzas-worker`
- [ ] Comprobar: imagen sin `.env`, usuario sin privilegios, `/salud` responde
