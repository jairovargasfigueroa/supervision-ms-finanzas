<?php

namespace App\Cobros\Dominio;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fechas con las que se arma el resumen de cobros del tablero (009), en la zona del negocio (`America/La_Paz`,
 * docs/convenciones.md):
 *
 * - Vencido: un cobro `VENCIDO`, o `PENDIENTE` con `venceEn` anterior a hoy. Un cobro `PENDIENTE` pasa a `VENCIDO`
 *   al día siguiente de su vencimiento (docs/reglas-negocio.md); se cuenta también el `PENDIENTE` ya vencido para que
 *   el resumen no dependa de cuándo corre el proceso que cambia el estado guardado.
 * - Por vencer en 30 días: `PENDIENTE` con `venceEn` entre hoy y hoy + 30 días, ambos incluidos.
 */
final readonly class CorteResumen
{
    public const ZONA = 'America/La_Paz';

    public const DIAS_POR_VENCER = 30;

    private function __construct(
        /** Fecha de hoy en La Paz (a las 00:00). */
        public DateTimeImmutable $hoy,
        /** Último día que cuenta como «por vencer»: hoy + 30 días. */
        public DateTimeImmutable $limitePorVencer,
    ) {}

    /** El corte para el instante `$ahora` (en cualquier zona): toma la fecha que es en La Paz. */
    public static function al(DateTimeImmutable $ahora): self
    {
        $zona = new DateTimeZone(self::ZONA);
        $hoy = new DateTimeImmutable($ahora->setTimezone($zona)->format('Y-m-d'), $zona);

        return new self($hoy, $hoy->add(new DateInterval('P'.self::DIAS_POR_VENCER.'D')));
    }
}
