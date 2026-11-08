<?php

namespace App\Contratos\Dominio;

use App\Compartido\Dominio\Dinero;

/**
 * Saldo de un contrato (docs/reglas-negocio.md, «Contrato»): saldo disponible = monto − suma de cobros emitidos −
 * reservas vigentes (`RESERVADA`). Los cobros cuentan en cualquier estado (`PENDIENTE` o `VENCIDO`).
 */
final readonly class SaldoContrato
{
    public function __construct(
        public Dinero $monto,
        public Dinero $totalCobrado,
        public Dinero $totalReservado,
    ) {}

    public function disponible(): Dinero
    {
        return $this->monto->restar($this->totalCobrado)->restar($this->totalReservado);
    }

    /** Si el saldo disponible cubre `$monto` (saldo justo incluido): se puede reservar (008). */
    public function alcanzaPara(Dinero $monto): bool
    {
        return ! $this->disponible()->esMenorQue($monto);
    }
}
