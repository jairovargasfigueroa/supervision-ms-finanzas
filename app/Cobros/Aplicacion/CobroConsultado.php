<?php

namespace App\Cobros\Aplicacion;

use App\Cobros\Dominio\EstadoCobro;
use App\Compartido\Dominio\Dinero;
use DateTimeImmutable;

/**
 * Un cobro tal como lo lee `GET /cobros` (006): con el `obraId` de su contrato.
 */
final readonly class CobroConsultado
{
    public function __construct(
        public string $id,
        public string $numero,
        public string $obraId,
        public string $planillaId,
        public Dinero $montoNeto,
        public DateTimeImmutable $emitidoEn,
        public DateTimeImmutable $venceEn,
        public EstadoCobro $estado,
    ) {}
}
