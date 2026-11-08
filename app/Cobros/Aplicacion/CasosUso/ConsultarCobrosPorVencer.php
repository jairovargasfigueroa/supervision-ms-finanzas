<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Aplicacion\CobrosPorVencer;
use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Dominio\CorteResumen;
use App\Compartido\Dominio\ReglaNegocioException;
use App\Contratos\Aplicacion\Puertos\ContratoRepositorio;
use DateTimeImmutable;

/**
 * Herramienta MCP `cobros_por_vencer` (501): cobros `PENDIENTE` que vencen entre hoy y hoy + `dias` (La Paz) y, si se
 * piden, los vencidos, con el mismo cálculo del resumen del tablero (009, `CorteResumen`). Si se pide una obra sin
 * contrato en Finanzas, `OBRA_NO_ENCONTRADA`. Solo lee.
 */
final class ConsultarCobrosPorVencer
{
    public function __construct(
        private readonly CobroRepositorio $cobros,
        private readonly ContratoRepositorio $contratos,
    ) {}

    public function ejecutar(
        DateTimeImmutable $ahora,
        int $dias,
        bool $incluirVencidos,
        ?string $obraId,
        int $limite,
    ): CobrosPorVencer {
        if ($obraId !== null && ! $this->contratos->existeParaObra($obraId)) {
            throw new ReglaNegocioException('OBRA_NO_ENCONTRADA', 'Obra no encontrada', 404);
        }

        return $this->cobros->porVencer(CorteResumen::al($ahora, $dias), $incluirVencidos, $obraId, $limite);
    }
}
