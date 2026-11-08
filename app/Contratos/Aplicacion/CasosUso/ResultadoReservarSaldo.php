<?php

namespace App\Contratos\Aplicacion\CasosUso;

enum ResultadoReservarSaldo
{
    case Reservado;
    /** La planilla ya tenía una reserva vigente: se respondió de nuevo con la misma. */
    case YaReservado;
    case SaldoInsuficiente;
}
