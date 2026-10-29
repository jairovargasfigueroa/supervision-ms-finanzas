<?php

namespace App\Contratos\Infraestructura\Persistencia;

use App\Contratos\Aplicacion\Puertos\ContratoRepositorio;
use App\Contratos\Dominio\Contrato;
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
            'saldo_disponible' => $contrato->saldoDisponible()->aDecimal(),
            'firmado_en' => $contrato->firmadoEn->format('Y-m-d'),
        ]);
    }
}
