<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Aplicacion\DatosNotaCobro;
use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Aplicacion\Puertos\GeneradorPdfNotaCobro;
use App\Cobros\Dominio\NotaCobro;
use App\Compartido\Dominio\ReglaNegocioException;
use Illuminate\Support\Str;

/**
 * `POST /cobros/{id}/nota-cobro` (201, rol `automatizacion`): arma la nota de cobro con los datos de la planilla que
 * manda n8n, comprueba que sean los del cobro (`422 NOTA_COBRO_INCONSISTENTE`) y devuelve el PDF. No guarda nada.
 */
final readonly class GenerarNotaCobro
{
    public function __construct(
        private CobroRepositorio $cobros,
        private GeneradorPdfNotaCobro $pdf,
    ) {}

    /**
     * @return array{numero: string, pdf: string}
     *
     * @throws ReglaNegocioException `404 COBRO_NO_ENCONTRADO` o `422 NOTA_COBRO_INCONSISTENTE`
     */
    public function ejecutar(string $cobroId, DatosNotaCobro $datos): array
    {
        $cobro = Str::isUuid($cobroId) ? $this->cobros->buscar($cobroId) : null;
        if ($cobro === null) {
            throw RegistrarPagoCobro::noEncontrado();
        }

        $nota = NotaCobro::armar(
            cobroNumero: $cobro->numero,
            cobroPlanillaId: $cobro->planillaId,
            cobroObraId: $cobro->obraId,
            cobroMontoNeto: $cobro->montoNeto,
            emitidoEn: $cobro->emitidoEn,
            venceEn: $cobro->venceEn,
            clienteNombre: $datos->clienteNombre,
            obraId: $datos->obraId,
            obraNombre: $datos->obraNombre,
            planillaId: $datos->planillaId,
            planillaNumero: $datos->planillaNumero,
            periodo: $datos->periodo,
            lineas: $datos->lineas,
            subtotal: $datos->subtotal,
            descuentoAnticipo: $datos->descuentoAnticipo,
            retencion: $datos->retencion,
            neto: $datos->neto,
            anticipoPct: $datos->anticipoPct,
            retencionPct: $datos->retencionPct,
        );

        return ['numero' => $cobro->numero, 'pdf' => $this->pdf->generar($nota)];
    }
}
