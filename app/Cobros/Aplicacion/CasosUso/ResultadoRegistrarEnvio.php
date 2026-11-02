<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Dominio\EnvioCobro;

final readonly class ResultadoRegistrarEnvio
{
    public function __construct(
        public EnvioCobro $envio,
        /** `false` si el cobro ya tenía un envío de ese tipo (se devolvió el existente). */
        public bool $creado,
    ) {}
}
