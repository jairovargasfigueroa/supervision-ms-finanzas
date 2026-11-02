<?php

namespace App\Cobros\Infraestructura\Persistencia;

use App\Cobros\Aplicacion\Puertos\EnvioCobroRepositorio;
use App\Cobros\Dominio\EnvioCobro;
use App\Cobros\Dominio\TipoEnvioCobro;
use DateTimeZone;
use Illuminate\Support\Str;

final class EnvioCobroRepositorioEloquent implements EnvioCobroRepositorio
{
    public function siguienteId(): string
    {
        return (string) Str::uuid();
    }

    public function delCobro(string $cobroId, TipoEnvioCobro $tipo): ?EnvioCobro
    {
        $fila = EnvioCobroModelo::query()->where('cobro_id', $cobroId)->where('tipo', $tipo->value)->first();

        return $fila === null ? null : CobroRepositorioEloquent::envio($fila);
    }

    public function guardar(EnvioCobro $envio): void
    {
        // Con el builder (sin los casts del modelo) para guardar el instante en UTC con su zona (`timestamptz`).
        EnvioCobroModelo::query()->insert([
            'id' => $envio->id,
            'cobro_id' => $envio->cobroId,
            'tipo' => $envio->tipo->value,
            'destinatario' => $envio->destinatario,
            'enviado_en' => $envio->enviadoEn->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
            'ejecucion_n8n' => $envio->ejecucionN8n,
        ]);
    }
}
