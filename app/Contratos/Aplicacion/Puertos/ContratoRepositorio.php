<?php

namespace App\Contratos\Aplicacion\Puertos;

use App\Contratos\Aplicacion\ContratoConsultado;
use App\Contratos\Dominio\Contrato;

interface ContratoRepositorio
{
    /** Id (uuid) para un contrato nuevo. */
    public function siguienteId(): string;

    public function existeParaObra(string $obraId): bool;

    public function guardar(Contrato $contrato): void;

    /** El contrato de la obra con su saldo (suma de cobros y de reservas vigentes), o `null` si no tiene (006). */
    public function consultarPorObra(string $obraId): ?ContratoConsultado;
}
