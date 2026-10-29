<?php

namespace App\Cobros\Infraestructura\Persistencia;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Dominio\EstadoCobro;
use App\Compartido\Dominio\CriteriosPagina;
use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\Pagina;

final class CobroRepositorioEloquent implements CobroRepositorio
{
    /** Columna de `cobro` por cada `orden` del JSON. */
    private const COLUMNA_ORDEN = [
        'venceEn' => 'cobro.vence_en',
        'emitidoEn' => 'cobro.emitido_en',
    ];

    public function listar(?string $obraId, ?EstadoCobro $estado, CriteriosPagina $criterios): Pagina
    {
        // El `obraId` es el del contrato del cobro.
        $consulta = CobroModelo::query()
            ->join('contrato', 'contrato.id', '=', 'cobro.contrato_id')
            ->select('cobro.*', 'contrato.obra_id');
        if ($obraId !== null) {
            $consulta->where('contrato.obra_id', $obraId);
        }
        if ($estado !== null) {
            $consulta->where('cobro.estado', $estado->value);
        }

        $total = (clone $consulta)->count();
        $direccion = $criterios->direccion === 'desc' ? 'desc' : 'asc';
        $filas = $consulta
            ->orderBy(self::COLUMNA_ORDEN[$criterios->orden] ?? self::COLUMNA_ORDEN['venceEn'], $direccion)
            // `id` desempata para que las páginas no repitan ni salten elementos.
            ->orderBy('cobro.id', $direccion)
            ->offset($criterios->desplazamiento())
            ->limit($criterios->tamano)
            ->get();

        return new Pagina(
            items: $filas->map(fn (CobroModelo $fila) => new CobroConsultado(
                id: $fila->id,
                numero: $fila->numero,
                obraId: $fila->obra_id,
                planillaId: $fila->planilla_id,
                montoNeto: Dinero::desde($fila->monto_neto),
                emitidoEn: $fila->emitido_en->toDateTimeImmutable(),
                venceEn: $fila->vence_en->toDateTimeImmutable(),
                estado: EstadoCobro::from($fila->estado),
            ))->values()->all(),
            total: $total,
            pagina: $criterios->pagina,
            tamano: $criterios->tamano,
        );
    }
}
