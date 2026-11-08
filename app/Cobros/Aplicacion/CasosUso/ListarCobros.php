<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Dominio\EstadoCobro;
use App\Compartido\Dominio\CriteriosPagina;
use App\Compartido\Dominio\Pagina;

/**
 * `GET /cobros?obraId=&estado=` (006): cobros paginados. Solo lee; los emite la 008.
 */
final class ListarCobros
{
    public function __construct(private readonly CobroRepositorio $cobros) {}

    /**
     * @return Pagina<CobroConsultado>
     */
    public function ejecutar(?string $obraId, ?EstadoCobro $estado, CriteriosPagina $criterios): Pagina
    {
        return $this->cobros->listar($obraId, $estado, $criterios);
    }
}
