# 008 · Revisar planilla y saga · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/008-revisar-planilla-saga/` de la carpeta que agrupa los proyectos. Contratos: `docs/contratos.md` de este proyecto.

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [x] Migraciones `reserva_saldo` y `cobro` (planilla_id único, número correlativo por año)
- [x] Manejadores de `ReservarSaldo`, `EmitirCobro`, `LiberarReserva` idempotentes, con sus respuestas
- [ ] Tarea diaria 00:10 (La Paz) que marca cobros vencidos; `GET /cobros/{id}`
- [ ] Pruebas de la spec
