<?php

namespace App\Contratos\Infraestructura\Http;

use App\Contratos\Aplicacion\ContratoConsultado;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un elemento de `GET /contratos` (docs/contratos.md), en `camelCase`.
 *
 * @property ContratoConsultado $resource
 */
class ContratoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contrato = $this->resource;
        $saldo = $contrato->saldo;

        return [
            'id' => $contrato->id,
            'obraId' => $contrato->obraId,
            'monto' => $saldo->monto->aNumero(),
            'plazoDias' => $contrato->plazoDias,
            'anticipoPct' => $contrato->anticipoPct,
            'retencionPct' => $contrato->retencionPct,
            'firmadoEn' => $contrato->firmadoEn->format('Y-m-d'),
            'saldoDisponible' => $saldo->disponible()->aNumero(),
            'totalCobrado' => $saldo->totalCobrado->aNumero(),
            'totalReservado' => $saldo->totalReservado->aNumero(),
        ];
    }
}
