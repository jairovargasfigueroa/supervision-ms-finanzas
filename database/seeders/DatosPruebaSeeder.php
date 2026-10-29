<?php

namespace Database\Seeders;

use App\Cobros\Dominio\Cobro;
use App\Cobros\Dominio\EstadoCobro;
use App\Compartido\Dominio\Dinero;
use App\Compartido\Infraestructura\Semilla\FechaRelativa;
use App\Compartido\Infraestructura\Semilla\GuardaDatosPrueba;
use App\Contratos\Dominio\EstadoReserva;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Datos de prueba de Finanzas (011, docs/datos-prueba.md sección 8): 3 contratos, 3 reservas `CONSUMIDA` y 3 cobros,
 * leídos de `datos/finanzas.json` con las fechas relativas a D (`SEMILLA_FECHA` o hoy en La Paz).
 *
 * - Lo derivado se calcula, no se copia: el número del cobro (`COB-<año>-<NNNN>`, correlativo por año de `emitido_en`
 *   en orden de emisión), su vencimiento (+30 días, `Cobro::emitir`) y su estado (`VENCIDO` si vence antes de D). El
 *   saldo del contrato no se guarda (se calcula al leer) y el correlativo de la saga sigue solo (toma el mayor del año).
 * - Idempotente: inserta solo lo que no existe por `id` (`insertOrIgnore`), sin pisar lo existente, en una transacción.
 * - Sin eventos: no escribe en `outbox_evento` ni en `mensaje_procesado`.
 *
 * `php artisan db:seed --class=DatosPruebaSeeder --force` (con `SEMILLA_FECHA=AAAA-MM-DD` si se quiere una D fija).
 */
class DatosPruebaSeeder extends Seeder
{
    public const ARCHIVO = __DIR__.'/datos/finanzas.json';

    /** Lo agrega la 201 (proveedores); si existe, se carga primero. */
    private const SEEDER_PROVEEDORES = 'Database\\Seeders\\ProveedorSeeder';

    public function run(): void
    {
        GuardaDatosPrueba::comprobar();

        $semillaFecha = config('semilla.fecha');
        $d = FechaRelativa::fechaDeCarga(
            is_string($semillaFecha) ? $semillaFecha : null,
            CarbonImmutable::now()->toDateTimeImmutable(),
        );
        $datos = self::leerDatos();

        if (class_exists(self::SEEDER_PROVEEDORES)) {
            $this->call(self::SEEDER_PROVEEDORES);
        }

        $contratos = array_map(fn (array $fila): array => self::contrato($fila, $d), self::lista($datos, 'contratos'));
        $reservas = array_map(fn (array $fila): array => self::reserva($fila), self::lista($datos, 'reservas'));
        $cobros = self::cobros(self::lista($datos, 'cobros'), $d);

        DB::transaction(function () use ($contratos, $reservas, $cobros): void {
            foreach (['contrato' => $contratos, 'reserva_saldo' => $reservas, 'cobro' => $cobros] as $tabla => $filas) {
                DB::table($tabla)->insertOrIgnore($filas);
                self::comprobarInsertados($tabla, $filas);
            }
        });

        if (isset($this->command)) {
            $this->command->info(sprintf(
                'Finanzas · datos de prueba con D = %s: %d contratos, %d reservas, %d cobros.',
                $d->format('Y-m-d'),
                DB::table('contrato')->count(),
                DB::table('reserva_saldo')->count(),
                DB::table('cobro')->count(),
            ));
        }
    }

    /**
     * Filas de `cobro`: en orden de emisión (a igual fecha, el del archivo), con el correlativo de su año.
     *
     * @param  list<array<mixed>>  $filas
     * @return list<array<string, string>>
     */
    public static function cobros(array $filas, DateTimeImmutable $d): array
    {
        $cobros = array_map(fn (array $fila): array => [
            'fila' => $fila,
            'emitidoEn' => FechaRelativa::fecha(self::texto($fila, 'emitidoEn'), $d),
        ], $filas);
        // usort es estable: a igual fecha conserva el orden del archivo.
        usort($cobros, fn (array $a, array $b): int => $a['emitidoEn'] <=> $b['emitidoEn']);

        $correlativos = [];
        $resultado = [];
        foreach ($cobros as ['fila' => $fila, 'emitidoEn' => $emitidoEn]) {
            $anio = (int) $emitidoEn->format('Y');
            $correlativos[$anio] = ($correlativos[$anio] ?? 0) + 1;
            $cobro = Cobro::emitir(
                self::texto($fila, 'id'),
                self::texto($fila, 'contratoId'),
                self::texto($fila, 'planillaId'),
                $correlativos[$anio],
                Dinero::desde(self::numero($fila, 'montoNeto')),
                $emitidoEn,
            );
            $estado = $cobro->venceEn->format('Y-m-d') < $d->format('Y-m-d') ? EstadoCobro::Vencido : $cobro->estado;

            $resultado[] = [
                'id' => $cobro->id,
                'contrato_id' => $cobro->contratoId,
                'planilla_id' => $cobro->planillaId,
                'numero' => $cobro->numero,
                'monto_neto' => $cobro->montoNeto->aDecimal(),
                'emitido_en' => $cobro->emitidoEn->format('Y-m-d'),
                'vence_en' => $cobro->venceEn->format('Y-m-d'),
                'estado' => $estado->value,
            ];
        }

        return $resultado;
    }

    /**
     * @param  array<mixed>  $fila
     * @return array<string, string|int>
     */
    private static function contrato(array $fila, DateTimeImmutable $d): array
    {
        return [
            'id' => self::texto($fila, 'id'),
            'obra_id' => self::texto($fila, 'obraId'),
            'monto' => Dinero::desde(self::numero($fila, 'monto'))->aDecimal(),
            'plazo_dias' => (int) self::numero($fila, 'plazoDias'),
            'anticipo_pct' => number_format(self::numero($fila, 'anticipoPct'), 2, '.', ''),
            'retencion_pct' => number_format(self::numero($fila, 'retencionPct'), 2, '.', ''),
            'firmado_en' => FechaRelativa::fecha(self::texto($fila, 'firmadoEn'), $d)->format('Y-m-d'),
        ];
    }

    /**
     * @param  array<mixed>  $fila
     * @return array<string, string>
     */
    private static function reserva(array $fila): array
    {
        return [
            'id' => self::texto($fila, 'id'),
            'contrato_id' => self::texto($fila, 'contratoId'),
            'planilla_id' => self::texto($fila, 'planillaId'),
            'monto' => Dinero::desde(self::numero($fila, 'monto'))->aDecimal(),
            // Falla si el estado no es uno de los de la reserva.
            'estado' => EstadoReserva::from(self::texto($fila, 'estado'))->value,
        ];
    }

    /**
     * `insertOrIgnore` también ignora un choque con otra clave única (otro contrato de la misma obra, otro cobro con el
     * mismo número o de la misma planilla): eso no es «ya estaba», es un dato que no cuadra, y se avisa.
     *
     * @param  list<array<string, mixed>>  $filas
     */
    private static function comprobarInsertados(string $tabla, array $filas): void
    {
        $ids = array_column($filas, 'id');
        $existentes = DB::table($tabla)->whereIn('id', $ids)->count();
        if ($existentes !== count($ids)) {
            throw new RuntimeException(
                "Datos de prueba: en «{$tabla}» no se pudieron cargar ".(count($ids) - $existentes).' filas porque '
                .'chocan con datos que ya existen (misma obra, planilla o número con otro id). Vacía con '
                .'`php artisan datos-prueba:vaciar` o usa `recargar` de infra.',
            );
        }
    }

    /** @return array<mixed> */
    private static function leerDatos(): array
    {
        $texto = file_get_contents(self::ARCHIVO);
        if ($texto === false) {
            throw new RuntimeException('Datos de prueba: no se pudo leer '.self::ARCHIVO.'.');
        }
        $datos = json_decode($texto, true, flags: JSON_THROW_ON_ERROR);

        return is_array($datos) ? $datos : throw new RuntimeException('Datos de prueba: el archivo no es un objeto.');
    }

    /**
     * @param  array<mixed>  $datos
     * @return list<array<mixed>>
     */
    private static function lista(array $datos, string $clave): array
    {
        $lista = $datos[$clave] ?? null;
        if (! is_array($lista) || ! array_is_list($lista)) {
            throw new RuntimeException("Datos de prueba: falta la lista «{$clave}».");
        }

        return array_map(
            fn (mixed $fila): array => is_array($fila) ? $fila : throw new RuntimeException("Datos de prueba: «{$clave}» tiene un elemento que no es un objeto."),
            $lista,
        );
    }

    /** @param  array<mixed>  $fila */
    private static function texto(array $fila, string $campo): string
    {
        $valor = $fila[$campo] ?? null;

        return is_string($valor) && $valor !== ''
            ? $valor
            : throw new RuntimeException("Datos de prueba: falta el campo de texto «{$campo}».");
    }

    /** @param  array<mixed>  $fila */
    private static function numero(array $fila, string $campo): int|float
    {
        $valor = $fila[$campo] ?? null;

        return is_int($valor) || is_float($valor)
            ? $valor
            : throw new RuntimeException("Datos de prueba: falta el campo numérico «{$campo}».");
    }
}
