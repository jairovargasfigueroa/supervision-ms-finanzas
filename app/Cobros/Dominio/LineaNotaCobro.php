<?php

namespace App\Cobros\Dominio;

use App\Compartido\Dominio\Dinero;

/** Una línea de la planilla en la nota de cobro (201): la partida y lo ejecutado en el periodo. */
final readonly class LineaNotaCobro
{
    public function __construct(
        public string $codigo,
        public string $nombre,
        public string $unidad,
        public float $cantidadPeriodo,
        public Dinero $precioUnitario,
        public Dinero $monto,
    ) {}
}
