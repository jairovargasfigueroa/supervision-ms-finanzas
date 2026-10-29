<?php

namespace App\Cobros\Aplicacion\CasosUso;

enum ResultadoEmitirCobro
{
    case Emitido;
    /** La planilla ya tenía cobro: se respondió de nuevo con el existente. */
    case YaEmitido;
    /** Se respondió `CobroFallido`. */
    case Fallido;
}
