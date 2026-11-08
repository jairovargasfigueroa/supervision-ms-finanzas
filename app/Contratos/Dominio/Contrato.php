<?php

namespace App\Contratos\Dominio;

use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\ReglaNegocioException;
use DateTimeImmutable;

/**
 * Contrato de una obra (docs/reglas-negocio.md, «Contrato»). Un contrato por obra.
 *
 * Validaciones: monto > 0, plazo > 0, anticipo y retención entre 0 y 100, y anticipo + retención < 100.
 * El saldo disponible no se guarda: se calcula al leer con sus cobros y reservas (SaldoContrato).
 */
final class Contrato
{
    /** Motivos de rechazo, textos exactos de la spec 002 (diseño, «Finanzas»). */
    public const MOTIVO_MONTO = 'El monto del contrato debe ser mayor a 0';

    public const MOTIVO_PLAZO = 'El plazo del contrato debe ser mayor a 0 días';

    public const MOTIVO_PORCENTAJES = 'El anticipo y la retención deben estar entre 0 % y 100 %';

    public const MOTIVO_SUMA_PORCENTAJES = 'El anticipo más la retención deben sumar menos de 100 %';

    /** Código del rechazo cuando falla alguna validación. */
    public const CODIGO_INVALIDO = 'CONTRATO_INVALIDO';

    private function __construct(
        public readonly string $id,
        public readonly string $obraId,
        public readonly Dinero $monto,
        public readonly int $plazoDias,
        public readonly float $anticipoPct,
        public readonly float $retencionPct,
        public readonly DateTimeImmutable $firmadoEn,
    ) {}

    /**
     * @throws ReglaNegocioException `CONTRATO_INVALIDO` con los motivos que fallan unidos por «; »
     */
    public static function registrar(
        string $id,
        string $obraId,
        Dinero $monto,
        int $plazoDias,
        float $anticipoPct,
        float $retencionPct,
        DateTimeImmutable $firmadoEn,
    ): self {
        $anticipoPct = round($anticipoPct, 2);
        $retencionPct = round($retencionPct, 2);

        $motivos = self::motivosDeRechazo($monto, $plazoDias, $anticipoPct, $retencionPct);
        if ($motivos !== []) {
            throw new ReglaNegocioException(self::CODIGO_INVALIDO, implode('; ', $motivos));
        }

        return new self($id, $obraId, $monto, $plazoDias, $anticipoPct, $retencionPct, $firmadoEn);
    }

    /**
     * Motivos por los que no se puede registrar, en el orden de la spec. Vacío si es válido.
     *
     * @return list<string>
     */
    public static function motivosDeRechazo(Dinero $monto, int $plazoDias, float $anticipoPct, float $retencionPct): array
    {
        $motivos = [];
        if (! $monto->esPositivo()) {
            $motivos[] = self::MOTIVO_MONTO;
        }
        if ($plazoDias <= 0) {
            $motivos[] = self::MOTIVO_PLAZO;
        }
        if (! self::entre0y100($anticipoPct) || ! self::entre0y100($retencionPct)) {
            $motivos[] = self::MOTIVO_PORCENTAJES;
        }
        if (round($anticipoPct + $retencionPct, 2) >= 100) {
            $motivos[] = self::MOTIVO_SUMA_PORCENTAJES;
        }

        return $motivos;
    }

    private static function entre0y100(float $porcentaje): bool
    {
        return $porcentaje >= 0 && $porcentaje <= 100;
    }
}
