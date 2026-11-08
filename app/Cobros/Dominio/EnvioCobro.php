<?php

namespace App\Cobros\Dominio;

use DateTimeImmutable;

/**
 * Un correo que n8n envió por un cobro y anotó en Finanzas (201, tabla `envio_cobro`).
 */
final readonly class EnvioCobro
{
    public function __construct(
        public string $id,
        public string $cobroId,
        public TipoEnvioCobro $tipo,
        public string $destinatario,
        /** Instante del envío (se guarda y se informa en UTC). */
        public DateTimeImmutable $enviadoEn,
        /** Id de la ejecución de n8n, para buscarla en su panel. */
        public ?string $ejecucionN8n = null,
    ) {}
}
