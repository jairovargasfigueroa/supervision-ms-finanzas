<?php

namespace App\Cobros\Dominio;

use App\Compartido\Dominio\FechaNegocio;
use DateInterval;
use DateTimeImmutable;

/**
 * Fechas con las que se arma el resumen de cobros del tablero (009), en la zona del negocio (`America/La_Paz`,
 * docs/convenciones.md):
 *
 * - Vencido: un cobro `VENCIDO`, o `PENDIENTE` con `venceEn` anterior a hoy. Un cobro `PENDIENTE` pasa a `VENCIDO`
 *   al día siguiente de su vencimiento (docs/reglas-negocio.md); se cuenta también el `PENDIENTE` ya vencido para que
 *   el resumen no dependa de cuándo corre el proceso que cambia el estado guardado.
 * - Por vencer en 30 días: `PENDIENTE` con `venceEn` entre hoy y hoy + 30 días, ambos incluidos.
 *
 * La herramienta MCP `cobros_por_vencer` (501) usa el mismo corte con otro número de días (`dias`, 1 a 90).
 */
final readonly class CorteResumen
{
    public const ZONA = FechaNegocio::ZONA;

    public const DIAS_POR_VENCER = 30;

    private function __construct(
        /** Fecha de hoy en La Paz (a las 00:00). */
        public DateTimeImmutable $hoy,
        /** Último día que cuenta como «por vencer»: hoy + 30 días (o hoy + `dias`, 501). */
        public DateTimeImmutable $limitePorVencer,
    ) {}

    /** El corte para el instante `$ahora` (en cualquier zona): toma la fecha que es en La Paz. */
    public static function al(DateTimeImmutable $ahora, int $diasPorVencer = self::DIAS_POR_VENCER): self
    {
        $hoy = FechaNegocio::hoy($ahora);

        return new self($hoy, $hoy->add(new DateInterval('P'.$diasPorVencer.'D')));
    }

    /** Días calendario desde hoy hasta `$vence` (por su fecha): negativo si ya venció, 0 si vence hoy (501). */
    public function diasHasta(DateTimeImmutable $vence): int
    {
        $desde = new DateTimeImmutable($this->hoy->format('Y-m-d'));
        $hasta = new DateTimeImmutable($vence->format('Y-m-d'));

        return (int) $desde->diff($hasta)->format('%r%a');
    }
}
