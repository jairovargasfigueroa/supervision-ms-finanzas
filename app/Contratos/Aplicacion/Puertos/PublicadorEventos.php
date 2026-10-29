<?php

namespace App\Contratos\Aplicacion\Puertos;

/**
 * Publica un evento de Finanzas (`evento.finanzas.<tipo>`). Se guarda en el outbox dentro de la transacción en
 * curso, así que solo sale si el dato se guarda.
 */
interface PublicadorEventos
{
    /**
     * @param  string  $tipo  en PascalCase y en pasado, por ejemplo `ContratoRegistrado`
     * @param  array<string, mixed>  $datos
     * @param  string  $correlacionId  el del mensaje al que se responde
     */
    public function publicar(string $tipo, array $datos, string $correlacionId): void;
}
