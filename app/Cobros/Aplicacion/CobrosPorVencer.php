<?php

namespace App\Cobros\Aplicacion;

use App\Cobros\Dominio\CorteResumen;

/**
 * Lo que devuelve `cobros_por_vencer` (501): los cobros por vencer (y los vencidos, si se piden) hasta el límite de
 * filas, ordenados por vencimiento, con los totales de todos los que cumplen el filtro (no solo los devueltos).
 */
final readonly class CobrosPorVencer
{
    /**
     * @param  list<CobroConsultado>  $cobros
     */
    public function __construct(
        /** El corte con que se buscó: hoy en La Paz y hoy + `dias`. */
        public CorteResumen $corte,
        public array $cobros,
        public TotalCobros $vencidos,
        public TotalCobros $porVencer,
        /** Había más cobros que el límite. */
        public bool $truncado,
    ) {}
}
