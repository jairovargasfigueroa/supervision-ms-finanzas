# 201 · Compra automática por stock bajo · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/201-compra-automatica/` de la carpeta que agrupa los proyectos. Contratos: `docs/contratos.md` de este proyecto.

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [ ] Migraciones `proveedor`, `orden_compra` (con `creada_en`, estado `CREADA`, `numero` único, índice único parcial por obra y material abiertos) y `cuenta_por_pagar` (con `cantidad_recibida`)
- [ ] `ProveedorSeeder` con los tres proveedores de ids fijos, llamado desde `DatabaseSeeder`
- [ ] Middleware `rol:automatizacion`; `GET /proveedores` (admin, gerente)
- [ ] `POST /ordenes-compra`: idempotencia por `evento_origen_id`, orden abierta existente, cálculo de cantidad y monto, número `OC-<año>-NNNN`, outbox `OrdenCompraCreada`, choque concurrente
- [ ] `POST /ordenes-compra/{id}/enviada` y `GET /ordenes-compra` (con proveedor y cuenta por pagar)
- [ ] `POST /cuentas-por-pagar`: idempotencia por `ingreso_id`, `409 ORDEN_YA_RECIBIDA`, fecha de emisión en La Paz, vencimiento a 30 días, orden `RECIBIDA`
- [ ] Pruebas de la spec
