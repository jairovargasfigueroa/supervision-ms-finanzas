# 201 · Cobranza automática · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/201-cobranza-automatica/` de la carpeta que agrupa los proyectos. Contratos: `docs/contratos.md` de este proyecto. (Reemplaza a la compra automática por stock bajo: se borró `docs/specs/201-compra-automatica/`.)

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [x] Aplicar «Cambios a contratos y datos» que tocan este proyecto (`docs/datos.md`, `docs/contratos.md`, `docs/servicio.md`); quitar de la documentación las tablas de la compra automática (`proveedor`, `orden_compra`, `cuenta_por_pagar`)
- [x] Migración: `cobro` agrega `pagado_en`, `comprobante`, `pago_registrado_por`, `pago_registrado_en` y el estado `PAGADO`; tabla `envio_cobro` con único (`cobro_id`, `tipo`)
- [x] `POST /cobros/{id}/pago` (solo `admin`): validaciones de fecha, `409 COBRO_YA_PAGADO`, bloqueo de la fila, outbox `CobroPagado` en la misma transacción
- [x] `GET /cobros` y `GET /cobros/{id}` con `pagadoEn`, `comprobante` y `envios`; filtro `estado=PAGADO`; `totalCobrado` del contrato cuenta también los `PAGADO`; la tarea `cobros:marcar-vencidos` y `GET /cobros/resumen` ignoran los `PAGADO` (pruebas)
- [ ] Middleware `rol:automatizacion`; `GET /cobros/recordatorios` con las reglas de por vencer (hoy a hoy + 3) y vencido, sin los que ya tienen ese envío
- [ ] `POST /cobros/{id}/envios`: idempotente por (`cobro_id`, `tipo`), `201` nuevo o `200` existente
- [ ] Nota de cobro en PDF: librería del diseño, vista Blade, `POST /cobros/{id}/nota-cobro` con la comprobación de coherencia (`422 NOTA_COBRO_INCONSISTENTE`); prueba de que el PDF se genera y trae número, neto y la leyenda
- [ ] Semilla (011): envío `NOTA_COBRO` de los 3 cobros cargados
- [ ] Pruebas de la spec y del servidor MCP (`cobros_por_vencer` no devuelve cobros `PAGADO`)
