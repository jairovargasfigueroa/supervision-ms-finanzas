<?php

namespace App\Cobros\Aplicacion\Puertos;

use App\Cobros\Dominio\NotaCobro;

/** Convierte la nota de cobro en un PDF (201). */
interface GeneradorPdfNotaCobro
{
    /** @return string el contenido del PDF */
    public function generar(NotaCobro $nota): string;
}
