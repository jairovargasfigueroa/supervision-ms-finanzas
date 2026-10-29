<?php

namespace App\Cobros\Aplicacion\Puertos;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\ResumenCobros;
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
}
