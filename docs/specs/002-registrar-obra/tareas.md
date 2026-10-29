# 002 · Registrar obra · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/002-registrar-obra/` de la carpeta que agrupa los proyectos. Contratos: `docs/contratos.md` de este proyecto.

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [x] Instalar `php-amqplib/php-amqplib` 3 y crear la conexión con el exchange `plataforma`
- [x] Migraciones: `contrato` (obra_id único), `outbox_evento`, `mensaje_procesado`
- [x] Comando `mensajeria:publicar` (outbox cada 1 s) y `mensajeria:consumir` (proceso largo con inbox)
- [ ] Dominio `Contrato` con sus validaciones; caso de uso que atiende `ObraRegistrada` y publica `ContratoRegistrado` o `ContratoRechazado`
- [ ] Pruebas: válido, inválido y mensaje repetido
