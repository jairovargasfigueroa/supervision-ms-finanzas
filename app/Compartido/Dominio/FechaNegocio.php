<?php

namespace App\Compartido\Dominio;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Fechas del negocio en su zona horaria, `America/La_Paz` (docs/convenciones.md): «hoy» es la fecha que es en La Paz,
 * aunque el servidor o el instante estén en UTC.
 */
final class FechaNegocio
{
    public const ZONA = 'America/La_Paz';

    /** La fecha que es en La Paz en el instante `$ahora` (en cualquier zona), a las 00:00 de La Paz. */
    public static function hoy(DateTimeImmutable $ahora): DateTimeImmutable
    {
        $zona = new DateTimeZone(self::ZONA);

        return new DateTimeImmutable($ahora->setTimezone($zona)->format('Y-m-d'), $zona);
    }
}
