<?php

namespace App\Contratos\Aplicacion\Puertos;

use App\Contratos\Dominio\ReservaSaldo;

/**
 * Reservas de saldo de los contratos (008).
 */
interface ReservaSaldoRepositorio
{
    /** Id (uuid) para una reserva nueva. */
    public function siguienteId(): string;

    /** La reserva vigente (`RESERVADA`) de la planilla, o `null`. Hay a lo sumo una. */
    public function vigenteDePlanilla(string $planillaId): ?ReservaSaldo;

    /** La reserva con ese id, bloqueada hasta el fin de la transacción (`FOR UPDATE`), o `null` si no existe. */
    public function bloquear(string $reservaId): ?ReservaSaldo;

    /** Crea la reserva o guarda su estado. */
    public function guardar(ReservaSaldo $reserva): void;
}
