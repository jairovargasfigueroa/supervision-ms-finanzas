<?php

namespace App\Contratos\Infraestructura\Mensajeria;

use App\Compartido\Infraestructura\Mensajeria\OutboxEvento;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
use App\Contratos\Aplicacion\Puertos\PublicadorEventos;

/**
 * Deja el evento en `outbox_evento` con la clave `evento.finanzas.<tipo>`; `mensajeria:publicar` lo envía.
 */
final class PublicadorEventosOutbox implements PublicadorEventos
{
    public function publicar(string $tipo, array $datos, string $correlacionId): void
    {
        OutboxEvento::registrar('evento.'.Sobre::ORIGEN.'.'.$tipo, Sobre::nuevo($tipo, $datos, $correlacionId));
    }
}
