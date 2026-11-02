<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Compartido\Dominio\FechaNegocio;
use App\Compartido\Dominio\ReglaNegocioException;
use App\Contratos\Aplicacion\Puertos\PublicadorEventos;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `POST /cobros/{id}/pago` (201, solo `admin`): registra el pago completo del cobro. En una transacción bloquea la fila
 * del cobro, comprueba que no esté pagado (`409 COBRO_YA_PAGADO`) y la fecha (entre la emisión y hoy en La Paz;
 * `422 FECHA_PAGO_INVALIDA`), lo pasa a `PAGADO` y deja `CobroPagado` en el outbox. El saldo del contrato no cambia.
 */
final readonly class RegistrarPagoCobro
{
    public function __construct(
        private CobroRepositorio $cobros,
        private PublicadorEventos $eventos,
    ) {}

    /**
     * @param  string|null  $comprobante  null si no se indicó
     * @param  string  $registradoPor  `sub` del token de quien registra
     *
     * @throws ReglaNegocioException `404 COBRO_NO_ENCONTRADO`, `409 COBRO_YA_PAGADO` o `422 FECHA_PAGO_INVALIDA`
     */
    public function ejecutar(
        string $cobroId,
        DateTimeImmutable $pagadoEn,
        ?string $comprobante,
        string $registradoPor,
        DateTimeImmutable $ahora,
    ): CobroConsultado {
        // Un id que no es uuid no puede existir (y PostgreSQL rechazaría compararlo con la columna uuid).
        if (! Str::isUuid($cobroId)) {
            throw self::noEncontrado();
        }

        return DB::transaction(function () use ($cobroId, $pagadoEn, $comprobante, $registradoPor, $ahora) {
            $cobro = $this->cobros->bloquear($cobroId) ?? throw self::noEncontrado();

            $pagado = $cobro->registrarPago($pagadoEn, $comprobante, $registradoPor, FechaNegocio::hoy($ahora), $ahora);
            $this->cobros->guardarPago($pagado);

            $consultado = $this->cobros->buscar($cobroId) ?? throw self::noEncontrado();
            $this->eventos->publicar('CobroPagado', [
                'cobroId' => $consultado->id,
                'planillaId' => $consultado->planillaId,
                'obraId' => $consultado->obraId,
                'numero' => $consultado->numero,
                'montoNeto' => $consultado->montoNeto->aNumero(),
                'pagadoEn' => $pagadoEn->format('Y-m-d'),
                'comprobante' => $comprobante,
            ], correlacionId: $consultado->id);

            return $consultado;
        });
    }

    public static function noEncontrado(): ReglaNegocioException
    {
        return new ReglaNegocioException('COBRO_NO_ENCONTRADO', 'No se encontró el cobro.', 404);
    }
}
