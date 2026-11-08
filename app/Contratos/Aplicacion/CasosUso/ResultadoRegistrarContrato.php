<?php

namespace App\Contratos\Aplicacion\CasosUso;

enum ResultadoRegistrarContrato
{
    case Registrado;
    case Rechazado;
    /** La obra ya tenía contrato: no se hizo nada. */
    case YaExistia;
}
