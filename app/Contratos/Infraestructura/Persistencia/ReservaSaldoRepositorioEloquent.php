<?php

namespace App\Contratos\Infraestructura\Persistencia;

use App\Compartido\Dominio\Dinero;
use App\Contratos\Aplicacion\Puertos\ReservaSaldoRepositorio;
use App\Contratos\Dominio\EstadoReserva;
use App\Contratos\Dominio\ReservaSaldo;
use Illuminate\Support\Str;

final class ReservaSaldoRepositorioEloquent implements ReservaSaldoRepositorio
{
    public function siguienteId(): string
    {
        return (string) Str::uuid();
    }

    public function vigenteDePlanilla(string $planillaId): ?ReservaSaldo
    {
        $fila = ReservaSaldoModelo::query()
            ->where('planilla_id', $planillaId)
            ->where('estado', EstadoReserva::Reservada->value)
            ->first();

        return $fila === null ? null : self::aDominio($fila);
    }

    public function bloquear(string $reservaId): ?ReservaSaldo
    {
        $fila = ReservaSaldoModelo::query()->whereKey($reservaId)->lockForUpdate()->first();

        return $fila === null ? null : self::aDominio($fila);
    }

    public function guardar(ReservaSaldo $reserva): void
    {
        ReservaSaldoModelo::query()->updateOrCreate(['id' => $reserva->id], [
            'contrato_id' => $reserva->contratoId,
            'planilla_id' => $reserva->planillaId,
            'monto' => $reserva->monto->aDecimal(),
            'estado' => $reserva->estado()->value,
        ]);
    }

    private static function aDominio(ReservaSaldoModelo $fila): ReservaSaldo
    {
        return ReservaSaldo::reconstruir(
            id: $fila->id,
            contratoId: $fila->contrato_id,
            planillaId: $fila->planilla_id,
            monto: Dinero::desde($fila->monto),
            estado: EstadoReserva::from($fila->estado),
        );
    }
}
