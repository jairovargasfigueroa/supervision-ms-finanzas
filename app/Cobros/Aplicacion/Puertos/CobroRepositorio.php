<?php

namespace App\Cobros\Aplicacion\Puertos;

use App\Cobros\Aplicacion\CobroConsultado;
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
}
