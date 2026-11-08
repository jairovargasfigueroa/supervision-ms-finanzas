<?php

namespace App\Cobros\Aplicacion;

/** Lo que devuelve `GET /cobros/resumen` (009): cobros vencidos y por vencer en 30 días, de todas las obras. */
final readonly class ResumenCobros
{
    public function __construct(
        public TotalCobros $vencidos,
        public TotalCobros $porVencer30Dias,
    ) {}
}
