<?php

namespace App\Cobros\Aplicacion;

use App\Cobros\Dominio\CorteResumen;

/**
 * Lo que devuelve `GET /cobros/recordatorios` (201): los cobros que necesitan recordatorio hoy, en dos grupos (un
 * cobro está a lo sumo en uno), ordenados por vencimiento y número.
 */
final readonly class RecordatoriosCobros
{
    /**
     * @param  list<CobroConsultado>  $porVencer
     * @param  list<CobroConsultado>  $vencidos
     */
    public function __construct(
        /** Hoy en La Paz y hoy + 3 días. */
        public CorteResumen $corte,
        public array $porVencer,
        public array $vencidos,
    ) {}
}
