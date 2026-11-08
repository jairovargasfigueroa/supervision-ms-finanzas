<?php

namespace App\Contratos\Aplicacion\CasosUso;

use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\ReglaNegocioException;
use App\Contratos\Aplicacion\Puertos\ContratoRepositorio;
use App\Contratos\Aplicacion\Puertos\PublicadorEventos;
use App\Contratos\Dominio\Contrato;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Atiende `ObraRegistrada` (002): crea el contrato y publica `ContratoRegistrado`, o publica `ContratoRechazado`
 * con el motivo si los datos no son válidos.
 */
final readonly class RegistrarContrato
{
    public function __construct(
        private ContratoRepositorio $contratos,
        private PublicadorEventos $eventos,
    ) {}

    public function ejecutar(
        string $obraId,
        Dinero $monto,
        int $plazoDias,
        float $anticipoPct,
        float $retencionPct,
        DateTimeImmutable $firmadoEn,
        string $correlacionId,
    ): ResultadoRegistrarContrato {
        return DB::transaction(function () use ($obraId, $monto, $plazoDias, $anticipoPct, $retencionPct, $firmadoEn, $correlacionId) {
            // Un contrato por obra: si ya existe (otro mensaje de la misma obra), no se crea ni se publica nada.
            if ($this->contratos->existeParaObra($obraId)) {
                return ResultadoRegistrarContrato::YaExistia;
            }

            try {
                $contrato = Contrato::registrar(
                    $this->contratos->siguienteId(), $obraId, $monto, $plazoDias, $anticipoPct, $retencionPct, $firmadoEn,
                );
            } catch (ReglaNegocioException $rechazo) {
                $this->eventos->publicar('ContratoRechazado', ['obraId' => $obraId, 'motivo' => $rechazo->getMessage()], $correlacionId);

                return ResultadoRegistrarContrato::Rechazado;
            }

            $this->contratos->guardar($contrato);
            $this->eventos->publicar('ContratoRegistrado', ['obraId' => $obraId, 'contratoId' => $contrato->id], $correlacionId);

            return ResultadoRegistrarContrato::Registrado;
        });
    }
}
