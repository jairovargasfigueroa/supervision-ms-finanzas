# 006 · Consultar obra · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/006-consultar-obra/` de la carpeta que agrupa los proyectos. Contratos: `docs/contratos.md` de este proyecto.

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [ ] Validación de token con `firebase/php-jwt` y JWKS (si no quedó en la fase 1) y middleware de roles
- [ ] `GET /contratos?obraId=` con saldo, total cobrado y reservado; `GET /cobros?obraId=&estado=`
- [ ] Pruebas
