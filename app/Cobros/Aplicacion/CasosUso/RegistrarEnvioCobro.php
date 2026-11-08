<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Aplicacion\Puertos\EnvioCobroRepositorio;
use App\Cobros\Dominio\EnvioCobro;
use App\Cobros\Dominio\TipoEnvioCobro;
use App\Compartido\Dominio\ReglaNegocioException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `POST /cobros/{id}/envios` (201, rol `automatizacion`): n8n anota un correo que ya envió. Idempotente por
 * (`cobro_id`, `tipo`): si el cobro ya tiene un envío de ese tipo, lo devuelve sin cambiarlo; si no, lo crea con
 * `enviadoEn` = ahora. Bloquea la fila del cobro para que dos anotaciones a la vez no choquen.
 */
final readonly class RegistrarEnvioCobro
{
    public function __construct(
        private CobroRepositorio $cobros,
        private EnvioCobroRepositorio $envios,
    ) {}

    /**
     * @throws ReglaNegocioException `404 COBRO_NO_ENCONTRADO`
     */
    public function ejecutar(
        string $cobroId,
        TipoEnvioCobro $tipo,
        string $destinatario,
        ?string $ejecucionN8n,
        DateTimeImmutable $ahora,
    ): ResultadoRegistrarEnvio {
        if (! Str::isUuid($cobroId)) {
            throw RegistrarPagoCobro::noEncontrado();
        }

        return DB::transaction(function () use ($cobroId, $tipo, $destinatario, $ejecucionN8n, $ahora) {
            $this->cobros->bloquear($cobroId) ?? throw RegistrarPagoCobro::noEncontrado();

            $existente = $this->envios->delCobro($cobroId, $tipo);
            if ($existente !== null) {
                return new ResultadoRegistrarEnvio($existente, creado: false);
            }

            $envio = new EnvioCobro($this->envios->siguienteId(), $cobroId, $tipo, $destinatario, $ahora, $ejecucionN8n);
            $this->envios->guardar($envio);

            return new ResultadoRegistrarEnvio($envio, creado: true);
        });
    }
}
