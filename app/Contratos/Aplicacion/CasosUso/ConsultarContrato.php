<?php

namespace App\Contratos\Aplicacion\CasosUso;

use App\Contratos\Aplicacion\ContratoConsultado;
use App\Contratos\Aplicacion\Puertos\ContratoRepositorio;

/**
 * `GET /contratos?obraId=` (006): el contrato de la obra con su saldo disponible, total cobrado y total reservado, o
 * ninguno si la obra no tiene contrato. Solo lee.
 */
final class ConsultarContrato
{
    public function __construct(private readonly ContratoRepositorio $contratos) {}

    /**
     * @return list<ContratoConsultado> 0 o 1 elemento
     */
    public function ejecutar(string $obraId): array
    {
        $contrato = $this->contratos->consultarPorObra($obraId);

        return $contrato === null ? [] : [$contrato];
    }
}
