<?php

namespace App\Contratos\Infraestructura\Persistencia;

use App\Cobros\Infraestructura\Persistencia\CobroModelo;
use App\Compartido\Dominio\Dinero;
use App\Contratos\Aplicacion\ContratoConsultado;
use App\Contratos\Aplicacion\Puertos\ContratoRepositorio;
use App\Contratos\Dominio\Contrato;
use App\Contratos\Dominio\EstadoReserva;
use App\Contratos\Dominio\SaldoContrato;
use Illuminate\Support\Str;

final class ContratoRepositorioEloquent implements ContratoRepositorio
{
    public function siguienteId(): string
    {
        return (string) Str::uuid();
    }

    public function existeParaObra(string $obraId): bool
    {
        return ContratoModelo::query()->where('obra_id', $obraId)->exists();
    }

    public function guardar(Contrato $contrato): void
    {
        ContratoModelo::query()->create([
            'id' => $contrato->id,
            'obra_id' => $contrato->obraId,
            'monto' => $contrato->monto->aDecimal(),
            'plazo_dias' => $contrato->plazoDias,
            'anticipo_pct' => number_format($contrato->anticipoPct, 2, '.', ''),
            'retencion_pct' => number_format($contrato->retencionPct, 2, '.', ''),
            'firmado_en' => $contrato->firmadoEn->format('Y-m-d'),
        ]);
    }

    public function consultarPorObra(string $obraId): ?ContratoConsultado
    {
        $fila = ContratoModelo::query()->where('obra_id', $obraId)->first();
        if ($fila === null) {
            return null;
        }

        // Cobros emitidos en cualquier estado y solo las reservas vigentes (docs/reglas-negocio.md, «Contrato»).
        $cobrado = CobroModelo::query()->where('contrato_id', $fila->id)->sum('monto_neto');
        $reservado = ReservaSaldoModelo::query()
            ->where('contrato_id', $fila->id)
            ->where('estado', EstadoReserva::Reservada->value)
            ->sum('monto');

        return new ContratoConsultado(
            id: $fila->id,
            obraId: $fila->obra_id,
            plazoDias: $fila->plazo_dias,
            anticipoPct: (float) $fila->anticipo_pct,
            retencionPct: (float) $fila->retencion_pct,
            firmadoEn: $fila->firmado_en->toDateTimeImmutable(),
            saldo: new SaldoContrato(
                monto: Dinero::desde($fila->monto),
                totalCobrado: self::dinero($cobrado),
                totalReservado: self::dinero($reservado),
            ),
        );
    }

    /** La suma llega como texto (PostgreSQL), número (SQLite de las pruebas) o 0 si no hay filas. */
    private static function dinero(mixed $suma): Dinero
    {
        return Dinero::desde(is_numeric($suma) ? (string) $suma : '0');
    }
}
