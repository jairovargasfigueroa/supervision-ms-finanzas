<?php

namespace App\Cobros\Aplicacion;

use App\Compartido\Dominio\Dinero;

/** Cuántos cobros y la suma de su `montoNeto` (un indicador del resumen del tablero, 009). */
final readonly class TotalCobros
{
    public function __construct(
        public int $cantidad,
        public Dinero $monto,
    ) {}
}
