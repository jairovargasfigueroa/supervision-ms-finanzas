<?php

namespace App\Cobros\Infraestructura\Http;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\RecordatoriosCobros;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta de `GET /cobros/recordatorios` (201): `{ hoy, porVencer[], vencidos[] }`, en `camelCase`.
 *
 * @property RecordatoriosCobros $resource
 */
class RecordatoriosResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $corte = $this->resource->corte;

        return [
            'hoy' => $corte->hoy->format('Y-m-d'),
            'porVencer' => array_map(
                fn (CobroConsultado $cobro) => self::cobro($cobro) + ['diasParaVencer' => $corte->diasHasta($cobro->venceEn)],
                $this->resource->porVencer,
            ),
            'vencidos' => array_map(
                fn (CobroConsultado $cobro) => self::cobro($cobro) + ['diasVencido' => -$corte->diasHasta($cobro->venceEn)],
                $this->resource->vencidos,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cobro(CobroConsultado $cobro): array
    {
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
