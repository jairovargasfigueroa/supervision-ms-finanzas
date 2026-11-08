<?php

namespace App\Contratos\Infraestructura\Mensajeria;

use App\Compartido\Infraestructura\Mensajeria\ConsumidorBase;
use App\Compartido\Infraestructura\Mensajeria\LectorDatos;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
use App\Contratos\Aplicacion\CasosUso\ReservarSaldo;
use Illuminate\Support\Facades\Log;

/**
 * `comando.finanzas.ReservarSaldo` (008): `planillaId, obraId, monto`. Responde `SaldoReservado` o `SaldoInsuficiente`
 * con el `correlacionId` de la saga. Datos que faltan o mal formados: directo a `plataforma.fallidos`.
 */
final class ReservarSaldoManejador extends ConsumidorBase
{
    public function __construct(private readonly ReservarSaldo $reservarSaldo) {}

    protected function manejar(Sobre $sobre): void
    {
        $planillaId = LectorDatos::uuid($sobre, 'planillaId');
        $resultado = $this->reservarSaldo->ejecutar(
            planillaId: $planillaId,
            obraId: LectorDatos::uuid($sobre, 'obraId'),
            monto: LectorDatos::montoPositivo($sobre, 'monto'),
            correlacionId: $sobre->correlacionId,
        );

        Log::info('ReservarSaldo atendido.', ['id' => $sobre->id, 'planillaId' => $planillaId, 'resultado' => $resultado->name]);
    }
}
