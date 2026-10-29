<?php

namespace App\Cobros\Infraestructura\Persistencia;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Aplicacion\ResumenCobros;
use App\Cobros\Aplicacion\TotalCobros;
use App\Cobros\Dominio\Cobro;
use App\Cobros\Dominio\CorteResumen;
use App\Cobros\Dominio\EstadoCobro;
use App\Cobros\Dominio\NumeroCobro;
use App\Compartido\Dominio\CriteriosPagina;
use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\Pagina;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CobroRepositorioEloquent implements CobroRepositorio
{
    /** Primera clave del bloqueo de PostgreSQL que ordena la serie de números de cobro (la segunda es el año). */
    private const BLOQUEO_NUMERO_COBRO = 8008;

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

    public function siguienteId(): string
    {
        return (string) Str::uuid();
    }

    public function deLaPlanilla(string $planillaId): ?Cobro
    {
        $fila = CobroModelo::query()->where('planilla_id', $planillaId)->first();

        return $fila === null ? null : Cobro::reconstruir(
            id: $fila->id,
            contratoId: $fila->contrato_id,
            planillaId: $fila->planilla_id,
            numero: $fila->numero,
            montoNeto: Dinero::desde($fila->monto_neto),
            emitidoEn: $fila->emitido_en->toDateTimeImmutable(),
            venceEn: $fila->vence_en->toDateTimeImmutable(),
            estado: EstadoCobro::from($fila->estado),
        );
    }

    public function siguienteCorrelativo(int $anio): int
    {
        // Bloqueo de transacción por año (se suelta al confirmar). El SQLite de las pruebas no lo necesita: escribe de
        // a una transacción. El índice único de `numero` respalda a los dos.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::BLOQUEO_NUMERO_COBRO, $anio]);
        }

        $prefijo = NumeroCobro::prefijoDelAnio($anio);
        // Se compara el correlativo como número (no como texto), por si algún año pasa de 9999 cobros.
        $fila = CobroModelo::query()
            ->toBase()
            ->where('numero', 'like', $prefijo.'%')
            ->selectRaw('MAX(CAST(SUBSTR(numero, ?) AS INTEGER)) AS ultimo', [strlen($prefijo) + 1])
            ->first();
        $ultimo = $fila->ultimo ?? 0;

        return (is_numeric($ultimo) ? (int) $ultimo : 0) + 1;
    }

    public function guardar(Cobro $cobro): void
    {
        CobroModelo::query()->create([
            'id' => $cobro->id,
            'contrato_id' => $cobro->contratoId,
            'planilla_id' => $cobro->planillaId,
            'numero' => $cobro->numero,
            'monto_neto' => $cobro->montoNeto->aDecimal(),
            'emitido_en' => $cobro->emitidoEn->format('Y-m-d'),
            'vence_en' => $cobro->venceEn->format('Y-m-d'),
            'estado' => $cobro->estado->value,
        ]);
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
