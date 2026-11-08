<?php

namespace App\Cobros\Infraestructura\Mensajeria;

use App\Cobros\Aplicacion\CasosUso\EmitirCobro;
use App\Compartido\Infraestructura\Mensajeria\ConsumidorBase;
use App\Compartido\Infraestructura\Mensajeria\LectorDatos;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * `comando.finanzas.EmitirCobro` (008): `planillaId, reservaId, monto`. Responde `CobroEmitido` o `CobroFallido` con el
 * `correlacionId` de la saga. Datos que faltan o mal formados: directo a `plataforma.fallidos`.
 */
final class EmitirCobroManejador extends ConsumidorBase
{
    public function __construct(private readonly EmitirCobro $emitirCobro) {}

    protected function manejar(Sobre $sobre): void
    {
        $planillaId = LectorDatos::uuid($sobre, 'planillaId');
        $resultado = $this->emitirCobro->ejecutar(
            planillaId: $planillaId,
            reservaId: LectorDatos::uuid($sobre, 'reservaId'),
            monto: LectorDatos::montoPositivo($sobre, 'monto'),
            correlacionId: $sobre->correlacionId,
            // `CarbonImmutable::now()` respeta `Carbon::setTestNow()` en las pruebas.
            ahora: CarbonImmutable::now(),
        );

        Log::info('EmitirCobro atendido.', ['id' => $sobre->id, 'planillaId' => $planillaId, 'resultado' => $resultado->name]);
    }
}
