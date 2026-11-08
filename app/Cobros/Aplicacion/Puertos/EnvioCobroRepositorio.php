<?php

namespace App\Cobros\Aplicacion\Puertos;

use App\Cobros\Dominio\EnvioCobro;
use App\Cobros\Dominio\TipoEnvioCobro;

/**
 * Correos que n8n envió por cada cobro (201, tabla `envio_cobro`): a lo sumo uno de cada tipo por cobro.
 */
interface EnvioCobroRepositorio
{
    /** Id (uuid) para un envío nuevo. */
    public function siguienteId(): string;

    /** El envío de ese tipo del cobro, o `null` si todavía no tiene. */
    public function delCobro(string $cobroId, TipoEnvioCobro $tipo): ?EnvioCobro;

    /** Crea el envío. */
    public function guardar(EnvioCobro $envio): void;
}
