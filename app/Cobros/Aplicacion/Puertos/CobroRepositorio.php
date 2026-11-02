<?php

namespace App\Cobros\Aplicacion\Puertos;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\CobrosPorVencer;
use App\Cobros\Aplicacion\RecordatoriosCobros;
use App\Cobros\Aplicacion\ResumenCobros;
use App\Cobros\Dominio\Cobro;
use App\Cobros\Dominio\CorteResumen;
use App\Cobros\Dominio\EstadoCobro;
use App\Compartido\Dominio\CriteriosPagina;
use App\Compartido\Dominio\Pagina;
use DateTimeImmutable;

interface CobroRepositorio
{
    /** Campos del JSON por los que se puede ordenar `GET /cobros` (por defecto `venceEn asc`). */
    public const ORDENES = ['venceEn', 'emitidoEn'];

    /**
     * Cobros paginados, filtrados por obra (la de su contrato) y estado si se indican.
     *
     * @return Pagina<CobroConsultado>
     */
    public function listar(?string $obraId, ?EstadoCobro $estado, CriteriosPagina $criterios): Pagina;

    /**
     * Cantidad y suma de `monto_neto` de los cobros de todas las obras (009), con las fechas del corte:
     * vencidos = `VENCIDO`, o `PENDIENTE` con `vence_en` anterior a `$corte->hoy`; por vencer = `PENDIENTE` con
     * `vence_en` entre `$corte->hoy` y `$corte->limitePorVencer`, ambos incluidos.
     */
    public function resumir(CorteResumen $corte): ResumenCobros;

    /**
     * Cobros por vencer (y vencidos si `$incluirVencidos`) con las mismas condiciones que `resumir`, de una obra (la de
     * su contrato) o de todas, ordenados por `vence_en` y `numero`, hasta `$limite` (501). Los totales cuentan todos
     * los que cumplen el filtro; los vencidos, solo si se incluyen (si no, en cero).
     */
    public function porVencer(CorteResumen $corte, bool $incluirVencidos, ?string $obraId, int $limite): CobrosPorVencer;

    /**
     * Cobros que necesitan recordatorio (201), con las fechas del corte (hoy y hoy + 3): por vencer = `PENDIENTE` con
     * `vence_en` entre hoy y el límite, sin envío `RECORDATORIO_POR_VENCER`; vencidos = `VENCIDO`, o `PENDIENTE` con
     * `vence_en` anterior a hoy, sin envío `RECORDATORIO_VENCIDO`. Ordenados por `vence_en` y `numero`.
     */
    public function paraRecordar(CorteResumen $corte): RecordatoriosCobros;

    /** Id (uuid) para un cobro nuevo (008). */
    public function siguienteId(): string;

    /** El cobro de la planilla, o `null` si todavía no tiene (hay a lo sumo uno, 008). */
    public function deLaPlanilla(string $planillaId): ?Cobro;

    /**
     * El siguiente correlativo del número de cobro para ese año: el mayor ya usado + 1, o 1 si es el primero (008).
     * Bloquea la serie del año hasta el fin de la transacción, así dos emisiones a la vez no toman el mismo.
     */
    public function siguienteCorrelativo(int $anio): int;

    /** Crea el cobro (008). */
    public function guardar(Cobro $cobro): void;

    /** El cobro con ese id, con el `obraId` de su contrato, o `null` si no existe (`GET /cobros/{id}`, 008). */
    public function buscar(string $id): ?CobroConsultado;

    /**
     * El cobro con ese id, con su fila bloqueada hasta el fin de la transacción (`FOR UPDATE`), o `null` si no existe
     * (201: registrar el pago).
     */
    public function bloquear(string $id): ?Cobro;

    /** Guarda el pago registrado del cobro: estado, fecha, comprobante y quién y cuándo lo registró (201). */
    public function guardarPago(Cobro $cobro): void;

    /**
     * Pasa a `VENCIDO` los cobros `PENDIENTE` con `vence_en` anterior a `$hoy` (fecha en La Paz, 008).
     *
     * @return int cuántos cambió
     */
    public function marcarVencidos(DateTimeImmutable $hoy): int;
}
