<?php

namespace App\Cobros\Aplicacion;

use App\Cobros\Dominio\LineaNotaCobro;
use App\Compartido\Dominio\Dinero;

/**
 * Lo que n8n manda a `POST /cobros/{id}/nota-cobro` (201): el cliente, la obra y la planilla, leídos de Keycloak y de
 * Obras. Ya validado en su forma; su coherencia con el cobro la comprueba `NotaCobro::armar`.
 */
final readonly class DatosNotaCobro
{
    /**
     * @param  list<LineaNotaCobro>  $lineas
     */
    public function __construct(
        public string $clienteNombre,
        public string $obraId,
        public string $obraNombre,
        public string $planillaId,
        public int $planillaNumero,
        public string $periodo,
        public array $lineas,
        public Dinero $subtotal,
        public Dinero $descuentoAnticipo,
        public Dinero $retencion,
        public Dinero $neto,
        public float $anticipoPct,
        public float $retencionPct,
    ) {}
}
