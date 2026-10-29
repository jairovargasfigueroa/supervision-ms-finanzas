<?php

namespace App\Contratos\Aplicacion;

use App\Contratos\Dominio\SaldoContrato;
use DateTimeImmutable;

/**
 * Contrato de una obra tal como lo lee `GET /contratos` (006): sus datos y su saldo calculado con las reservas y los
 * cobros.
 */
final readonly class ContratoConsultado
{
    public function __construct(
        public string $id,
        public string $obraId,
        public int $plazoDias,
        public float $anticipoPct,
        public float $retencionPct,
        public DateTimeImmutable $firmadoEn,
        public SaldoContrato $saldo,
    ) {}
}
