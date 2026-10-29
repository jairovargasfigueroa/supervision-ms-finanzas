<?php

namespace App\Cobros\Infraestructura\Persistencia;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Aplicacion\ResumenCobros;
use App\Cobros\Aplicacion\TotalCobros;
use App\Cobros\Dominio\CorteResumen;
use App\Cobros\Dominio\EstadoCobro;
use App\Compartido\Dominio\CriteriosPagina;
use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\Pagina;
use Illuminate\Database\Eloquent\Builder;

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

    public function resumir(CorteResumen $corte): ResumenCobros
    {
        // `whereDate` compara solo la fecha, igual en PostgreSQL y en el SQLite de las pruebas.
        $hoy = $corte->hoy->format('Y-m-d');
        $vencidos = CobroModelo::query()->where(function (Builder $consulta) use ($hoy) {
            $consulta->where('estado', EstadoCobro::Vencido->value)
                ->orWhere(fn (Builder $pendiente) => $pendiente
                    ->where('estado', EstadoCobro::Pendiente->value)
                    ->whereDate('vence_en', '<', $hoy));
        });
        $porVencer = CobroModelo::query()
            ->where('estado', EstadoCobro::Pendiente->value)
            ->whereDate('vence_en', '>=', $hoy)
            ->whereDate('vence_en', '<=', $corte->limitePorVencer->format('Y-m-d'));

        return new ResumenCobros(self::total($vencidos), self::total($porVencer));
    }

    /**
     * @param  Builder<CobroModelo>  $consulta
     */
    private static function total(Builder $consulta): TotalCobros
    {
        $fila = $consulta->toBase()->selectRaw('COUNT(*) AS cantidad, COALESCE(SUM(monto_neto), 0) AS monto')->first();
        $monto = $fila->monto ?? 0;

        // La suma llega como texto (PostgreSQL) o número (SQLite de las pruebas).
        return new TotalCobros((int) ($fila->cantidad ?? 0), Dinero::desde(is_numeric($monto) ? (string) $monto : '0'));
    }
}
