<?php

namespace App\Contratos\Dominio;

use App\Compartido\Dominio\Dinero;
use InvalidArgumentException;
use LogicException;

/**
 * Reserva del neto de una planilla contra el saldo del contrato (008, paso 2 de la saga). Mientras está `RESERVADA`
 * descuenta del saldo; al emitirse el cobro pasa a `CONSUMIDA` y al compensar la saga, a `LIBERADA`.
 */
final class ReservaSaldo
{
    /** Motivo de `SaldoInsuficiente`, texto exacto de la spec 008 (criterio 8, docs/mensajes.md). */
    public const MOTIVO_SALDO_INSUFICIENTE = 'Saldo del contrato insuficiente';

    private function __construct(
        public readonly string $id,
        public readonly string $contratoId,
        public readonly string $planillaId,
        public readonly Dinero $monto,
        private EstadoReserva $estado,
    ) {}

    /**
     * Reserva nueva. Quien la crea comprobó antes que el saldo alcanza (`SaldoContrato::alcanzaPara`).
     */
    public static function reservar(string $id, string $contratoId, string $planillaId, Dinero $monto): self
    {
        if (! $monto->esPositivo()) {
            throw new InvalidArgumentException('El monto a reservar debe ser mayor a 0.');
        }

        return new self($id, $contratoId, $planillaId, $monto, EstadoReserva::Reservada);
    }

    /** Reserva ya guardada, tal como está. */
    public static function reconstruir(string $id, string $contratoId, string $planillaId, Dinero $monto, EstadoReserva $estado): self
    {
        return new self($id, $contratoId, $planillaId, $monto, $estado);
    }

    public function estado(): EstadoReserva
    {
        return $this->estado;
    }

    public function estaVigente(): bool
    {
        return $this->estado === EstadoReserva::Reservada;
    }

    /** Al emitirse el cobro de la planilla. Solo una reserva vigente se puede consumir. */
    public function consumir(): void
    {
        if (! $this->estaVigente()) {
            throw new LogicException("La reserva {$this->id} no está vigente: no se puede consumir.");
        }
        $this->estado = EstadoReserva::Consumida;
    }

    /**
     * Compensación de la saga: devuelve el monto al saldo. Idempotente: si ya no está vigente (liberada o consumida),
     * no cambia nada y devuelve false.
     */
    public function liberar(): bool
    {
        if (! $this->estaVigente()) {
            return false;
        }
        $this->estado = EstadoReserva::Liberada;

        return true;
    }
}
