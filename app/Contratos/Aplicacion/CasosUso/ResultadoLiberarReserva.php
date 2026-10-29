<?php

namespace App\Contratos\Aplicacion\CasosUso;

enum ResultadoLiberarReserva
{
    case Liberada;
    /** Ya estaba liberada o consumida: no se hizo nada. */
    case NoVigente;
    /** No hay una reserva con ese id para esa planilla: no se hizo nada. */
    case NoExiste;
}
