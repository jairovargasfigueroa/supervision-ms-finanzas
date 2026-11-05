# 601 · Preparar el despliegue · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/601-contenedores-ci/` de la carpeta que agrupa los proyectos. Al implementarla, estas tareas reemplazan a `services/finanzas/docs/specs/601-contenedores-ci/tareas.md` (eran del borrador anterior).

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [x] Aplicar «Cambios a contratos y datos» que tocan este proyecto (`docs/`, `CLAUDE.md`, `.env.example`)
- [x] Revisar que no quede ninguna dirección fija; `DB_SSLMODE`, `RABBITMQ_SSL`, modo producción (`APP_ENV`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`)
- [x] `Dockerfile` (FrankenPHP, PHP 8.3+, extensiones; `migrate --force` y servidor) y `.dockerignore`; la misma imagen corre `mensajeria:consumir`, `mensajeria:publicar` y `schedule:work`; prueba con `/salud`
- [x] Semilla: excepción `SEMILLA_DEMO=si` (solo carga); pruebas
- [ ] `.github/workflows/ci.yml`: job `prueba` (pint, phpstan y `php artisan test`) y job `imagen` (build en PR; *push* a GHCR con `sha-<7>` y `main` en `main`)
