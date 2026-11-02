<?php

namespace App\Cobros\Infraestructura\Persistencia;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\CobrosPorVencer;
use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Aplicacion\RecordatoriosCobros;
use App\Cobros\Aplicacion\ResumenCobros;
use App\Cobros\Aplicacion\TotalCobros;
use App\Cobros\Dominio\Cobro;
use App\Cobros\Dominio\CorteResumen;
use App\Cobros\Dominio\EnvioCobro;
use App\Cobros\Dominio\EstadoCobro;
use App\Cobros\Dominio\NumeroCobro;
use App\Cobros\Dominio\TipoEnvioCobro;
use App\Compartido\Dominio\CriteriosPagina;
use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\Pagina;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
        $consulta = self::conObra();
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
            items: self::conEnvios($filas->values()->all()),
            total: $total,
            pagina: $criterios->pagina,
            tamano: $criterios->tamano,
        );
    }

    public function buscar(string $id): ?CobroConsultado
    {
        $fila = self::conObra()->where('cobro.id', $id)->first();

        return $fila === null ? null : self::conEnvios([$fila])[0];
    }

    /**
     * Los cobros con sus envíos (201), leídos en una sola consulta y ordenados por `enviado_en`.
     *
     * @param  list<CobroModelo>  $filas
     * @return list<CobroConsultado>
     */
    private static function conEnvios(array $filas): array
    {
        $envios = [];
        if ($filas !== []) {
            $envios = EnvioCobroModelo::query()
                ->whereIn('cobro_id', array_map(fn (CobroModelo $fila) => $fila->id, $filas))
                ->orderBy('enviado_en')
                ->orderBy('id')
                ->get()
                ->groupBy('cobro_id')
                ->all();
        }

        return array_map(fn (CobroModelo $fila) => self::consultado(
            $fila,
            array_values(array_map(
                fn (EnvioCobroModelo $envio) => self::envio($envio),
                isset($envios[$fila->id]) ? $envios[$fila->id]->all() : [],
            )),
        ), $filas);
    }

    public static function envio(EnvioCobroModelo $fila): EnvioCobro
    {
        return new EnvioCobro(
            id: $fila->id,
            cobroId: $fila->cobro_id,
            tipo: TipoEnvioCobro::from($fila->tipo),
            destinatario: $fila->destinatario,
            enviadoEn: $fila->enviado_en->toDateTimeImmutable(),
            ejecucionN8n: $fila->ejecucion_n8n,
        );
    }

    public function marcarVencidos(DateTimeImmutable $hoy): int
    {
        // `whereDate` compara solo la fecha, igual en PostgreSQL y en el SQLite de las pruebas.
        return CobroModelo::query()
            ->where('estado', EstadoCobro::Pendiente->value)
            ->whereDate('vence_en', '<', $hoy->format('Y-m-d'))
            ->update(['estado' => EstadoCobro::Vencido->value]);
    }

    /**
     * Cobros con el `obra_id` de su contrato.
     *
     * @return Builder<CobroModelo>
     */
    private static function conObra(): Builder
    {
        return CobroModelo::query()
            ->join('contrato', 'contrato.id', '=', 'cobro.contrato_id')
            ->select('cobro.*', 'contrato.obra_id');
    }

    /**
     * @param  list<EnvioCobro>  $envios
     */
    private static function consultado(CobroModelo $fila, array $envios = []): CobroConsultado
    {
        return new CobroConsultado(
            id: $fila->id,
            numero: $fila->numero,
            obraId: $fila->obra_id,
            planillaId: $fila->planilla_id,
            montoNeto: Dinero::desde($fila->monto_neto),
            emitidoEn: $fila->emitido_en->toDateTimeImmutable(),
            venceEn: $fila->vence_en->toDateTimeImmutable(),
            estado: EstadoCobro::from($fila->estado),
            pagadoEn: $fila->pagado_en?->toDateTimeImmutable(),
            comprobante: $fila->comprobante,
            envios: $envios,
        );
    }

    public function resumir(CorteResumen $corte): ResumenCobros
    {
        return new ResumenCobros(
            self::total(CobroModelo::query()->where(self::vencidos($corte))),
            self::total(CobroModelo::query()->where(self::porVencerHasta($corte))),
        );
    }

    public function porVencer(CorteResumen $corte, bool $incluirVencidos, ?string $obraId, int $limite): CobrosPorVencer
    {
        $consulta = self::deLaObra(self::conObra(), $obraId)->where(function (Builder $consulta) use ($corte, $incluirVencidos) {
            $consulta->where(self::porVencerHasta($corte));
            if ($incluirVencidos) {
                $consulta->orWhere(self::vencidos($corte));
            }
        });
        // Uno de más para saber si se recortó.
        $filas = $consulta
            ->orderBy('cobro.vence_en')
            ->orderBy('cobro.numero')
            ->limit($limite + 1)
            ->get();

        return new CobrosPorVencer(
            corte: $corte,
            cobros: $filas->take($limite)->map(fn (CobroModelo $fila) => self::consultado($fila))->values()->all(),
            vencidos: $incluirVencidos
                ? self::total(self::deLaObra(CobroModelo::query(), $obraId)->where(self::vencidos($corte)))
                : new TotalCobros(0, Dinero::desde(0)),
            porVencer: self::total(self::deLaObra(CobroModelo::query(), $obraId)->where(self::porVencerHasta($corte))),
            truncado: $filas->count() > $limite,
        );
    }

    public function paraRecordar(CorteResumen $corte): RecordatoriosCobros
    {
        $buscar = fn (Closure $condicion, TipoEnvioCobro $sinEnvio): array => self::conObra()
            ->where($condicion)
            ->whereNotExists(fn (QueryBuilder $envios) => $envios
                ->selectRaw('1')
                ->from('envio_cobro')
                ->whereColumn('envio_cobro.cobro_id', 'cobro.id')
                ->where('envio_cobro.tipo', $sinEnvio->value))
            ->orderBy('cobro.vence_en')
            ->orderBy('cobro.numero')
            ->get()
            ->map(fn (CobroModelo $fila) => self::consultado($fila))
            ->values()
            ->all();

        return new RecordatoriosCobros(
            corte: $corte,
            porVencer: $buscar(self::porVencerHasta($corte), TipoEnvioCobro::RecordatorioPorVencer),
            vencidos: $buscar(self::vencidos($corte), TipoEnvioCobro::RecordatorioVencido),
        );
    }

    /**
     * Solo los cobros del contrato de la obra, si se indica (501).
     *
     * @param  Builder<CobroModelo>  $consulta
     * @return Builder<CobroModelo>
     */
    private static function deLaObra(Builder $consulta, ?string $obraId): Builder
    {
        if ($obraId === null) {
            return $consulta;
        }

        return $consulta->whereIn(
            'cobro.contrato_id',
            fn (QueryBuilder $contratos) => $contratos->select('id')->from('contrato')->where('obra_id', $obraId),
        );
    }

    /**
     * Vencidos (009): `VENCIDO`, o `PENDIENTE` con `vence_en` anterior a hoy. `whereDate` compara solo la fecha, igual
     * en PostgreSQL y en el SQLite de las pruebas.
     *
     * @return Closure(Builder<CobroModelo>): void
     */
    private static function vencidos(CorteResumen $corte): Closure
    {
        $hoy = $corte->hoy->format('Y-m-d');

        return function (Builder $consulta) use ($hoy): void {
            $consulta->where('cobro.estado', EstadoCobro::Vencido->value)
                ->orWhere(fn (Builder $pendiente) => $pendiente
                    ->where('cobro.estado', EstadoCobro::Pendiente->value)
                    ->whereDate('cobro.vence_en', '<', $hoy));
        };
    }

    /**
     * Por vencer (009): `PENDIENTE` con `vence_en` entre hoy y el límite del corte, ambos incluidos.
     *
     * @return Closure(Builder<CobroModelo>): void
     */
    private static function porVencerHasta(CorteResumen $corte): Closure
    {
        $hoy = $corte->hoy->format('Y-m-d');
        $limite = $corte->limitePorVencer->format('Y-m-d');

        return function (Builder $consulta) use ($hoy, $limite): void {
            $consulta->where('cobro.estado', EstadoCobro::Pendiente->value)
                ->whereDate('cobro.vence_en', '>=', $hoy)
                ->whereDate('cobro.vence_en', '<=', $limite);
        };
    }

    public function siguienteId(): string
    {
        return (string) Str::uuid();
    }

    public function deLaPlanilla(string $planillaId): ?Cobro
    {
        $fila = CobroModelo::query()->where('planilla_id', $planillaId)->first();

        return $fila === null ? null : self::cobro($fila);
    }

    public function bloquear(string $id): ?Cobro
    {
        $fila = CobroModelo::query()->whereKey($id)->lockForUpdate()->first();

        return $fila === null ? null : self::cobro($fila);
    }

    public function guardarPago(Cobro $cobro): void
    {
        // Con el builder (sin los casts del modelo) para guardar el instante con su zona (`timestamptz`).
        CobroModelo::query()->whereKey($cobro->id)->update([
            'estado' => $cobro->estado->value,
            'pagado_en' => $cobro->pagadoEn?->format('Y-m-d'),
            'comprobante' => $cobro->comprobante,
            'pago_registrado_por' => $cobro->pagoRegistradoPor,
            'pago_registrado_en' => $cobro->pagoRegistradoEn?->format('Y-m-d H:i:sP'),
        ]);
    }

    private static function cobro(CobroModelo $fila): Cobro
    {
        return Cobro::reconstruir(
            id: $fila->id,
            contratoId: $fila->contrato_id,
            planillaId: $fila->planilla_id,
            numero: $fila->numero,
            montoNeto: Dinero::desde($fila->monto_neto),
            emitidoEn: $fila->emitido_en->toDateTimeImmutable(),
            venceEn: $fila->vence_en->toDateTimeImmutable(),
            estado: EstadoCobro::from($fila->estado),
            pagadoEn: $fila->pagado_en?->toDateTimeImmutable(),
            comprobante: $fila->comprobante,
            pagoRegistradoPor: $fila->pago_registrado_por,
            pagoRegistradoEn: $fila->pago_registrado_en?->toDateTimeImmutable(),
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
        $fila = $consulta->toBase()->selectRaw('COUNT(*) AS cantidad, COALESCE(SUM(cobro.monto_neto), 0) AS monto')->first();
        $monto = $fila->monto ?? 0;

        // La suma llega como texto (PostgreSQL) o número (SQLite de las pruebas).
        return new TotalCobros((int) ($fila->cantidad ?? 0), Dinero::desde(is_numeric($monto) ? (string) $monto : '0'));
    }
}
