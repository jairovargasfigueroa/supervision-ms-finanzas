# 011 · Datos de prueba · tareas de `services/finanzas`

Requisitos y diseño: `docs/specs/011-datos-prueba/` de la carpeta que agrupa los proyectos. Datos: `docs/datos-prueba.md` (secciones 4 y 8). Contratos: `docs/contratos.md` de este proyecto (no cambian). Ejecutar con el PHP de Herd desde PowerShell.

Se implementa **después de la 009**, cuando existen todas las tablas del sistema base. Si una capa agrega tablas a Finanzas (proveedores, órdenes de compra y cuentas por pagar de la 201), esa capa amplía esta semilla en el mismo trabajo.

Marca cada tarea al terminarla (con sus pruebas y su commit).

- [x] Resolvedor de fechas relativas en zona `America/La_Paz` con D desde `SEMILLA_FECHA` o la fecha de hoy; pruebas unitarias con la tabla del diseño
- [x] `database/seeders/datos/finanzas.json` y `DatosPruebaSeeder`: 3 contratos, 3 reservas `CONSUMIDA` y 3 cobros; calcula el número por año de `emitido_en` en orden de emisión, el vencimiento y el estado según D (el saldo disponible no se guarda: se calcula al leer; el correlativo de la 008 toma el mayor número del año, así que la serie sigue sola); `insertOrIgnore` en una transacción, y falla si una fila choca con otra clave única; llama a `ProveedorSeeder` si existe; guarda contra `APP_ENV=production` y base no local
- [x] Comando `php artisan datos-prueba:vaciar`: vacía todas las tablas del negocio (no `migrations`), con la misma guarda
- [ ] Pruebas Feature (D = 2026-10-28 y D = 2027-01-20): dos cargas sin duplicar; saldos; números y estados de los cobros; `GET /cobros/resumen` y `GET /contratos`; `outbox_evento` vacía; guarda
- [ ] README: cómo correr la semilla sola y vaciarla
