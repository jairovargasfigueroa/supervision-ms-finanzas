<?php

namespace App\Cobros\Infraestructura\Http;

use App\Cobros\Aplicacion\CobroConsultado;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un elemento de `GET /cobros` (docs/contratos.md), en `camelCase`.
 *
 * @property CobroConsultado $resource
 */
class CobroResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cobro = $this->resource;

        return [
            'id' => $cobro->id,
            'numero' => $cobro->numero,
            'obraId' => $cobro->obraId,
            'planillaId' => $cobro->planillaId,
            'montoNeto' => $cobro->montoNeto->aNumero(),
            'emitidoEn' => $cobro->emitidoEn->format('Y-m-d'),
            'venceEn' => $cobro->venceEn->format('Y-m-d'),
            'estado' => $cobro->estado->value,
        ];
    }
}
