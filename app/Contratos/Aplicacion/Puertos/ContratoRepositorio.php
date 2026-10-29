<?php

namespace App\Contratos\Aplicacion\Puertos;

use App\Contratos\Dominio\Contrato;

interface ContratoRepositorio
{
    /** Id (uuid) para un contrato nuevo. */
    public function siguienteId(): string;

    public function existeParaObra(string $obraId): bool;

    public function guardar(Contrato $contrato): void;
}
