<?php

namespace App\Cobros\Aplicacion\Puertos;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\ResumenCobros;
use App\Cobros\Dominio\Cobro;
use App\Cobros\Dominio\CorteResumen;
use App\Cobros\Dominio\EstadoCobro;
use App\Compartido\Dominio\CriteriosPagina;
use App\Compartido\Dominio\Pagina;

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
}
