<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Aplicacion\ResumenCobros;
use App\Cobros\Dominio\CorteResumen;
use DateTimeImmutable;

/**
 * `GET /cobros/resumen` (009): cobros vencidos y por vencer en 30 días de todas las obras, con la fecha de hoy en
 * La Paz (`CorteResumen`). Solo lee.
 */
final class ResumirCobros
{
    public function __construct(private readonly CobroRepositorio $cobros) {}

    public function ejecutar(DateTimeImmutable $ahora): ResumenCobros
    {
        return $this->cobros->resumir(CorteResumen::al($ahora));
    }
}
