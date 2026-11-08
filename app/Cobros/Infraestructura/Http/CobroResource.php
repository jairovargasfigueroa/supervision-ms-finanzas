<?php

namespace App\Cobros\Infraestructura\Http;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Dominio\EnvioCobro;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un elemento de `GET /cobros` (docs/contratos.md), en `camelCase`. También es la respuesta de `GET /cobros/{id}` y de
 * `POST /cobros/{id}/pago` (201).
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
            'pagadoEn' => $cobro->pagadoEn?->format('Y-m-d'),
            'comprobante' => $cobro->comprobante,
            'envios' => array_map(fn (EnvioCobro $envio) => self::envio($envio), $cobro->envios),
        ];
    }

    /**
     * Un envío de `envios[]` (201): `enviadoEn` en UTC, ISO 8601. También es la respuesta de `POST /cobros/{id}/envios`.
     *
     * @return array{tipo: string, destinatario: string, enviadoEn: string}
     */
    public static function envio(EnvioCobro $envio): array
    {
        return [
            'tipo' => $envio->tipo->value,
            'destinatario' => $envio->destinatario,
            'enviadoEn' => $envio->enviadoEn->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
