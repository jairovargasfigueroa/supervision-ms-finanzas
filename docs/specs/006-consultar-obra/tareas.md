# 006 · Consultar obra · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/006-consultar-obra/` de la carpeta que agrupa los proyectos. Contratos: `docs/contratos.md` de este proyecto.

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [x] Validación de token con `firebase/php-jwt` y JWKS (si no quedó en la fase 1) y middleware de roles
  - Ya hecha: el token (firma con el JWKS del realm, vencimiento, emisor y audiencia) quedó en la fase 1 (`ValidadorTokenKeycloak` y `MiddlewareToken`, pruebas en `tests/Feature/Compartido/AutenticacionTest.php`) y el middleware `rol:...` con los roles efectivos según `azp` en la 001 (`MiddlewareRol`, `RolesEfectivos`, `UsuarioActual`; pruebas en `RolTest.php` y `RolesEfectivosTest.php`). Las rutas de la 006 solo los usan.
- [x] `GET /contratos?obraId=` con saldo, total cobrado y reservado; `GET /cobros?obraId=&estado=`
- [ ] Pruebas
