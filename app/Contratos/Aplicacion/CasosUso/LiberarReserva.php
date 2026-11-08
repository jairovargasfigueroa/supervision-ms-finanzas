<?php

namespace App\Contratos\Aplicacion\CasosUso;

use App\Contratos\Aplicacion\Puertos\ContratoRepositorio;
use App\Contratos\Aplicacion\Puertos\PublicadorEventos;
use App\Contratos\Aplicacion\Puertos\ReservaSaldoRepositorio;
use Illuminate\Support\Facades\DB;

/**
 * Atiende `LiberarReserva` (008, compensación del paso 2): si la reserva está `RESERVADA`, la pasa a `LIBERADA` (el
 * monto vuelve al saldo) y publica `ReservaLiberada {planillaId, reservaId}`.
 *
 * Idempotente: una reserva ya liberada, ya consumida o que no existe no cambia nada ni publica nada.
 */
final readonly class LiberarReserva
{
    public function __construct(
        private ContratoRepositorio $contratos,
        private ReservaSaldoRepositorio $reservas,
        private PublicadorEventos $eventos,
    ) {}

    public function ejecutar(string $planillaId, string $reservaId, string $correlacionId): ResultadoLiberarReserva
    {
        return DB::transaction(function () use ($planillaId, $reservaId, $correlacionId) {
            $reserva = $this->reservas->bloquear($reservaId);
            if ($reserva === null || $reserva->planillaId !== $planillaId) {
                return ResultadoLiberarReserva::NoExiste;
            }
            // Mismo orden de bloqueo que al emitir el cobro (reserva y luego contrato).
            $this->contratos->bloquear($reserva->contratoId);
            if (! $reserva->liberar()) {
                return ResultadoLiberarReserva::NoVigente;
            }

            $this->reservas->guardar($reserva);
            $this->eventos->publicar('ReservaLiberada', ['planillaId' => $planillaId, 'reservaId' => $reservaId], $correlacionId);

            return ResultadoLiberarReserva::Liberada;
        });
    }
}
