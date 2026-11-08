<?php

namespace App\Contratos\Aplicacion\CasosUso;

use App\Compartido\Dominio\Dinero;
use App\Contratos\Aplicacion\Puertos\ContratoRepositorio;
use App\Contratos\Aplicacion\Puertos\PublicadorEventos;
use App\Contratos\Aplicacion\Puertos\ReservaSaldoRepositorio;
use App\Contratos\Dominio\ReservaSaldo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Atiende `ReservarSaldo` (008, paso 2 de la saga): reserva el neto de la planilla contra el saldo del contrato de la
 * obra y responde `SaldoReservado {planillaId, reservaId}`, o `SaldoInsuficiente {planillaId, motivo}` si no alcanza.
 *
 * Bloquea la fila del contrato antes de calcular el saldo: dos reservas a la vez no gastan el mismo saldo. Si la
 * planilla ya tiene una reserva vigente, responde de nuevo `SaldoReservado` con la misma (no crea otra).
 */
final readonly class ReservarSaldo
{
    public function __construct(
        private ContratoRepositorio $contratos,
        private ReservaSaldoRepositorio $reservas,
        private PublicadorEventos $eventos,
    ) {}

    /**
     * @throws RuntimeException si la obra no tiene contrato (no debería pasar: solo una obra con contrato tiene
     *                          planillas); el consumidor lo reintenta y, al tercer fallo, lo deja en fallidos
     */
    public function ejecutar(string $planillaId, string $obraId, Dinero $monto, string $correlacionId): ResultadoReservarSaldo
    {
        return DB::transaction(function () use ($planillaId, $obraId, $monto, $correlacionId) {
            $contrato = $this->contratos->consultarPorObra($obraId, bloquear: true);
            if ($contrato === null) {
                throw new RuntimeException('ReservarSaldo: la obra no tiene contrato.');
            }

            $vigente = $this->reservas->vigenteDePlanilla($planillaId);
            if ($vigente !== null) {
                $this->eventos->publicar('SaldoReservado', ['planillaId' => $planillaId, 'reservaId' => $vigente->id], $correlacionId);

                return ResultadoReservarSaldo::YaReservado;
            }

            if (! $contrato->saldo->alcanzaPara($monto)) {
                $this->eventos->publicar('SaldoInsuficiente', [
                    'planillaId' => $planillaId,
                    'motivo' => ReservaSaldo::MOTIVO_SALDO_INSUFICIENTE,
                ], $correlacionId);

                return ResultadoReservarSaldo::SaldoInsuficiente;
            }

            $reserva = ReservaSaldo::reservar($this->reservas->siguienteId(), $contrato->id, $planillaId, $monto);
            $this->reservas->guardar($reserva);
            $this->eventos->publicar('SaldoReservado', ['planillaId' => $planillaId, 'reservaId' => $reserva->id], $correlacionId);

            return ResultadoReservarSaldo::Reservado;
        });
    }
}
