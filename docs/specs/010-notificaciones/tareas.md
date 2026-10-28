# 010 · Notificaciones · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/010-notificaciones/` de la carpeta que agrupa los proyectos. Contratos: `docs/contratos.md` de este proyecto.

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [ ] `MarcarCobrosVencidos`: por cada cobro que pasa de `PENDIENTE` a `VENCIDO`, outbox `evento.finanzas.CobroVencido` (`cobroId, numero, obraId, planillaId, montoNeto, venceEn`) en la misma transacción; solo los cobros que cambian de estado en esa ejecución
- [ ] Pruebas de la spec (un evento por cobro vencido; segunda ejecución del mismo día sin eventos; `venceEn` y fecha de corte en hora de La Paz)
