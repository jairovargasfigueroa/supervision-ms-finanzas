<?php

namespace App\Cobros\Dominio;

use App\Compartido\Dominio\Dinero;
use DateInterval;
use DateTimeImmutable;

/**
 * Cobro al cliente por una planilla aprobada (008, paso 4 de la saga). Uno por planilla. Se emite `PENDIENTE` y vence
 * a los 30 días calendario desde su emisión (docs/reglas-negocio.md, decisión del equipo).
 */
final readonly class Cobro
{
    public const DIAS_PARA_VENCER = 30;

    private function __construct(
        public string $id,
        public string $contratoId,
        public string $planillaId,
        public string $numero,
        public Dinero $montoNeto,
        public DateTimeImmutable $emitidoEn,
        public DateTimeImmutable $venceEn,
        public EstadoCobro $estado,
    ) {}

    /**
     * @param  DateTimeImmutable  $emitidoEn  fecha de emisión en La Paz (`FechaNegocio::hoy`)
     * @param  int  $correlativo  el siguiente del año de `$emitidoEn`
     */
    public static function emitir(
        string $id,
        string $contratoId,
        string $planillaId,
        int $correlativo,
        Dinero $montoNeto,
        DateTimeImmutable $emitidoEn,
    ): self {
        return new self(
            $id,
            $contratoId,
            $planillaId,
            NumeroCobro::formatear((int) $emitidoEn->format('Y'), $correlativo),
            $montoNeto,
            $emitidoEn,
            $emitidoEn->add(new DateInterval('P'.self::DIAS_PARA_VENCER.'D')),
            EstadoCobro::Pendiente,
        );
    }

    /** Cobro ya guardado, tal como está. */
    public static function reconstruir(
        string $id,
        string $contratoId,
        string $planillaId,
        string $numero,
        Dinero $montoNeto,
        DateTimeImmutable $emitidoEn,
        DateTimeImmutable $venceEn,
        EstadoCobro $estado,
    ): self {
        return new self($id, $contratoId, $planillaId, $numero, $montoNeto, $emitidoEn, $venceEn, $estado);
    }
}
