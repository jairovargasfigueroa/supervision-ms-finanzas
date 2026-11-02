<?php

namespace App\Cobros\Infraestructura\Pdf;

use App\Cobros\Aplicacion\Puertos\GeneradorPdfNotaCobro;
use App\Cobros\Dominio\LineaNotaCobro;
use App\Cobros\Dominio\NotaCobro;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Nota de cobro en PDF con `barryvdh/laravel-dompdf` (201): vista Blade `pdf/nota-cobro` maquetada con tablas (dompdf
 * no admite flexbox ni grid), tamaño carta. Los textos van con Blade (escapados) y sin recursos remotos.
 */
final readonly class GeneradorPdfDompdf implements GeneradorPdfNotaCobro
{
    public function __construct(private string $empresaNombre) {}

    public function generar(NotaCobro $nota): string
    {
        return Pdf::loadView('pdf.nota-cobro', [
            'empresa' => $this->empresaNombre,
            'leyenda' => NotaCobro::LEYENDA,
            'numero' => $nota->cobroNumero,
            'emitidoEn' => FormatoBolivia::fecha($nota->emitidoEn),
            'venceEn' => FormatoBolivia::fecha($nota->venceEn),
            'cliente' => $nota->clienteNombre,
            'obra' => $nota->obraNombre,
            'planillaNumero' => $nota->planillaNumero,
            'periodo' => FormatoBolivia::periodo($nota->periodo),
            'lineas' => array_map(fn (LineaNotaCobro $linea) => [
                'codigo' => $linea->codigo,
                'nombre' => $linea->nombre,
                'unidad' => $linea->unidad,
                'cantidad' => FormatoBolivia::numero($linea->cantidadPeriodo),
                'precioUnitario' => FormatoBolivia::monto($linea->precioUnitario),
                'monto' => FormatoBolivia::monto($linea->monto),
            ], $nota->lineasDelPeriodo()),
            'subtotal' => FormatoBolivia::monto($nota->subtotal),
            'anticipoPct' => FormatoBolivia::numero($nota->anticipoPct),
            'descuentoAnticipo' => FormatoBolivia::monto($nota->descuentoAnticipo),
            'retencionPct' => FormatoBolivia::numero($nota->retencionPct),
            'retencion' => FormatoBolivia::monto($nota->retencion),
            'neto' => FormatoBolivia::monto($nota->neto),
        ])->setPaper('letter')->output();
    }
}
