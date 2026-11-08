<?php

namespace App\Cobros\Dominio;

use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\ReglaNegocioException;
use DateInterval;
use DateTimeImmutable;

/**
 * Cobro al cliente por una planilla aprobada (008, paso 4 de la saga). Uno por planilla. Se emite `PENDIENTE` y vence
 * a los 30 días calendario desde su emisión (docs/reglas-negocio.md, decisión del equipo). Administración registra su
 * pago completo (201): pasa a `PAGADO` y no cambia más.
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
        /** 201: fecha de pago en La Paz; null si no está pagado. */
        public ?DateTimeImmutable $pagadoEn = null,
        public ?string $comprobante = null,
        /** 201: `sub` de quien registró el pago. */
        public ?string $pagoRegistradoPor = null,
        public ?DateTimeImmutable $pagoRegistradoEn = null,
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
        ?DateTimeImmutable $pagadoEn = null,
        ?string $comprobante = null,
        ?string $pagoRegistradoPor = null,
        ?DateTimeImmutable $pagoRegistradoEn = null,
    ): self {
        return new self(
            $id, $contratoId, $planillaId, $numero, $montoNeto, $emitidoEn, $venceEn, $estado,
            $pagadoEn, $comprobante, $pagoRegistradoPor, $pagoRegistradoEn,
        );
    }

    public function estaPagado(): bool
    {
        return $this->estado === EstadoCobro::Pagado;
    }

    /**
     * Registra el pago completo (201): el cobro pasa a `PAGADO` con la fecha, el comprobante y quién lo registró.
     *
     * @param  DateTimeImmutable  $pagadoEn  fecha de pago (solo cuenta la fecha)
     * @param  DateTimeImmutable  $hoy  fecha de hoy en La Paz (`FechaNegocio::hoy`)
     * @param  DateTimeImmutable  $ahora  instante del registro
     *
     * @throws ReglaNegocioException `409 COBRO_YA_PAGADO` si ya está pagado; `422 FECHA_PAGO_INVALIDA` si la fecha es
     *                               posterior a hoy o anterior a la emisión
     */
    public function registrarPago(
        DateTimeImmutable $pagadoEn,
        ?string $comprobante,
        string $registradoPor,
        DateTimeImmutable $hoy,
        DateTimeImmutable $ahora,
    ): self {
        if ($this->estaPagado()) {
            throw new ReglaNegocioException('COBRO_YA_PAGADO', 'El cobro ya está pagado.', 409);
        }
        $fecha = $pagadoEn->format('Y-m-d');
        if ($fecha > $hoy->format('Y-m-d') || $fecha < $this->emitidoEn->format('Y-m-d')) {
            throw new ReglaNegocioException(
                'FECHA_PAGO_INVALIDA',
                'La fecha de pago no puede ser futura ni anterior a la emisión del cobro.',
                422,
            );
        }

        return new self(
            $this->id, $this->contratoId, $this->planillaId, $this->numero, $this->montoNeto, $this->emitidoEn,
            $this->venceEn, EstadoCobro::Pagado, $pagadoEn, $comprobante, $registradoPor, $ahora,
        );
    }
}
