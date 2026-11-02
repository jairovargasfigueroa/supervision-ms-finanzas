<?php

namespace App\Cobros\Dominio;

use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\ReglaNegocioException;
use DateTimeImmutable;

/**
 * Nota de cobro que se adjunta al correo del cliente (201). No es una factura y no se guarda: se arma en cada pedido
 * con los datos de la planilla que n8n leyó de Obras. Finanzas no los acepta a ciegas: `armar` comprueba que la
 * planilla y la obra sean las del cobro y que los totales cuadren con su neto.
 */
final readonly class NotaCobro
{
    public const LEYENDA = 'Este documento no es una factura';

    /**
     * @param  list<LineaNotaCobro>  $lineas  todas las de la planilla (la nota muestra solo las del periodo)
     */
    private function __construct(
        public string $cobroNumero,
        public DateTimeImmutable $emitidoEn,
        public DateTimeImmutable $venceEn,
        public string $clienteNombre,
        public string $obraNombre,
        public int $planillaNumero,
        /** `AAAA-MM`. */
        public string $periodo,
        public array $lineas,
        public Dinero $subtotal,
        public Dinero $descuentoAnticipo,
        public Dinero $retencion,
        public Dinero $neto,
        public float $anticipoPct,
        public float $retencionPct,
    ) {}

    /**
     * @param  string  $cobroPlanillaId  planilla del cobro
     * @param  string  $cobroObraId  obra del contrato del cobro
     * @param  Dinero  $cobroMontoNeto  neto del cobro
     * @param  list<LineaNotaCobro>  $lineas
     *
     * @throws ReglaNegocioException `422 NOTA_COBRO_INCONSISTENTE` si la planilla o la obra no son las del cobro, si el
     *                               neto no es el del cobro o si los totales no cuadran (a 2 decimales)
     */
    public static function armar(
        string $cobroNumero,
        string $cobroPlanillaId,
        string $cobroObraId,
        Dinero $cobroMontoNeto,
        DateTimeImmutable $emitidoEn,
        DateTimeImmutable $venceEn,
        string $clienteNombre,
        string $obraId,
        string $obraNombre,
        string $planillaId,
        int $planillaNumero,
        string $periodo,
        array $lineas,
        Dinero $subtotal,
        Dinero $descuentoAnticipo,
        Dinero $retencion,
        Dinero $neto,
        float $anticipoPct,
        float $retencionPct,
    ): self {
        if (strtolower($planillaId) !== strtolower($cobroPlanillaId)) {
            throw self::inconsistente('La planilla no es la del cobro.');
        }
        if (strtolower($obraId) !== strtolower($cobroObraId)) {
            throw self::inconsistente('La obra no es la del contrato del cobro.');
        }
        if (! $neto->esIgualA($cobroMontoNeto)) {
            throw self::inconsistente('El neto de la planilla no es el del cobro.');
        }
        $sumaLineas = array_reduce($lineas, fn (Dinero $suma, LineaNotaCobro $linea) => $suma->sumar($linea->monto), Dinero::desde(0));
        if (! $sumaLineas->esIgualA($subtotal)) {
            throw self::inconsistente('La suma de las líneas no es el subtotal.');
        }
        if (! $subtotal->restar($descuentoAnticipo)->restar($retencion)->esIgualA($neto)) {
            throw self::inconsistente('El subtotal menos el anticipo y la retención no es el neto.');
        }

        return new self(
            $cobroNumero, $emitidoEn, $venceEn, $clienteNombre, $obraNombre, $planillaNumero, $periodo, $lineas,
            $subtotal, $descuentoAnticipo, $retencion, $neto, $anticipoPct, $retencionPct,
        );
    }

    /**
     * Las líneas que se muestran: solo las de cantidad del periodo mayor a 0.
     *
     * @return list<LineaNotaCobro>
     */
    public function lineasDelPeriodo(): array
    {
        return array_values(array_filter($this->lineas, fn (LineaNotaCobro $linea) => $linea->cantidadPeriodo > 0));
    }

    private static function inconsistente(string $detalle): ReglaNegocioException
    {
        return new ReglaNegocioException(
            'NOTA_COBRO_INCONSISTENTE',
            'Los datos de la planilla no coinciden con el cobro. '.$detalle,
            422,
        );
    }
}
