<?php

namespace App\Contratos\Infraestructura\Mensajeria;

use App\Compartido\Infraestructura\Mensajeria\ConsumidorBase;
use App\Compartido\Infraestructura\Mensajeria\LectorDatos;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
use App\Contratos\Aplicacion\CasosUso\LiberarReserva;
use Illuminate\Support\Facades\Log;

/**
 * `comando.finanzas.LiberarReserva` (008, compensación): `planillaId, reservaId`. Publica `ReservaLiberada` solo si
 * la reserva estaba vigente. Idempotente. Datos que faltan o mal formados: directo a `plataforma.fallidos`.
 */
final class LiberarReservaManejador extends ConsumidorBase
{
    public function __construct(private readonly LiberarReserva $liberarReserva) {}

    protected function manejar(Sobre $sobre): void
    {
        $planillaId = LectorDatos::uuid($sobre, 'planillaId');
        $reservaId = LectorDatos::uuid($sobre, 'reservaId');
        $resultado = $this->liberarReserva->ejecutar($planillaId, $reservaId, $sobre->correlacionId);

        $contexto = ['id' => $sobre->id, 'planillaId' => $planillaId, 'reservaId' => $reservaId, 'resultado' => $resultado->name];
        Log::info('LiberarReserva atendido.', $contexto);
    }
}
