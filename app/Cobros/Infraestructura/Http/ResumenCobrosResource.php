<?php

namespace App\Cobros\Infraestructura\Http;

use App\Cobros\Aplicacion\ResumenCobros;
use App\Cobros\Aplicacion\TotalCobros;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta de `GET /cobros/resumen` (docs/contratos.md), en `camelCase`.
 *
 * @property ResumenCobros $resource
 */
class ResumenCobrosResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'vencidos' => self::total($this->resource->vencidos),
            'porVencer30Dias' => self::total($this->resource->porVencer30Dias),
        ];
    }

    /**
     * @return array{cantidad: int, monto: float}
     */
    private static function total(TotalCobros $total): array
    {
        return ['cantidad' => $total->cantidad, 'monto' => $total->monto->aNumero()];
    }
}
