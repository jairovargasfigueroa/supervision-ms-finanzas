<?php

namespace App\Contratos\Dominio;

/**
 * Estado de una reserva de saldo (docs/datos.md). Solo la `RESERVADA` está vigente y descuenta del saldo; al emitirse el
 * cobro pasa a `CONSUMIDA` (entonces descuenta el cobro) y al compensar la saga, a `LIBERADA` (008).
 */
enum EstadoReserva: string
{
    case Reservada = 'RESERVADA';
    case Liberada = 'LIBERADA';
    case Consumida = 'CONSUMIDA';
}
