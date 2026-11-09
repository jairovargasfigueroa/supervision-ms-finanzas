<?php

namespace Database\Seeders;

use App\Cobros\Dominio\Cobro;
use App\Cobros\Dominio\EstadoCobro;
use App\Cobros\Dominio\TipoEnvioCobro;
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
 * Datos de prueba de Finanzas (011, docs/datos-prueba.md sección 8): 3 contratos, 3 reservas `CONSUMIDA`, 3 cobros y,
 * desde la 201, el envío `NOTA_COBRO` de cada cobro (sin pagos ni recordatorios), leídos de `datos/finanzas.json` con
 * las fechas relativas a D (`SEMILLA_FECHA` o hoy en La Paz).
 *
 * - Lo derivado se calcula, no se copia: el número del cobro (`COB-<año>-<NNNN>`, correlativo por año de `emitido_en`
 *   en orden de emisión), su vencimiento (+30 días, `Cobro::emitir`) y su estado (`VENCIDO` si vence antes de D). El
 *   saldo del contrato no se guarda (se calcula al leer) y el correlativo de la saga sigue solo (toma el mayor del año).
 * - Idempotente: inserta solo lo que no existe por `id` (`insertOrIgnore`), sin pisar lo existente, en una transacción.
 * - Sin eventos: no escribe en `outbox_evento` ni en `mensaje_procesado`.
 *
 * 603: con `SEMILLA_HISTORIA=si` se suma `datos/finanzas-historia.json` (obras 4 a 6, generado por
 * `infra/semilla/historia/generar.mjs`): sus listas se agregan a las de la 011 y todo se carga y valida junto. Un cobro
 * con `pagadoEn` (`D-n`) y `comprobante` queda `PAGADO`, registrado por admin1 ese día a las 11:00 de La Paz.
 *
 * `php artisan db:seed --class=DatosPruebaSeeder --force` (con `SEMILLA_FECHA=AAAA-MM-DD` si se quiere una D fija).
 */
class DatosPruebaSeeder extends Seeder
{
    public const ARCHIVO = __DIR__.'/datos/finanzas.json';

    /** 603: historia de demostración que se suma con `SEMILLA_HISTORIA=si`. */
    public const ARCHIVO_HISTORIA = __DIR__.'/datos/finanzas-historia.json';

    /** admin1 (docs/datos-prueba.md, sección 3): quien figura registrando los pagos de la historia. */
    public const PAGOS_REGISTRADOS_POR = 'a0000000-0000-4000-8000-000000000001';

    private const LISTAS = ['contratos', 'reservas', 'cobros', 'envios'];

    public function run(): void
    {
        // 601: con SEMILLA_DEMO=si la carga corre también en producción (solo la carga, nunca el vaciado)
        GuardaDatosPrueba::comprobar(esCarga: true);

        $semillaFecha = config('semilla.fecha');
        $d = FechaRelativa::fechaDeCarga(
            is_string($semillaFecha) ? $semillaFecha : null,
            CarbonImmutable::now()->toDateTimeImmutable(),
        );
        $datos = self::leerDatos(config('semilla.historia') === true);

        $contratos = array_map(fn (array $fila): array => self::contrato($fila, $d), self::lista($datos, 'contratos'));
        $reservas = array_map(fn (array $fila): array => self::reserva($fila), self::lista($datos, 'reservas'));
        $cobros = self::cobros(self::lista($datos, 'cobros'), $d);
        $envios = array_map(fn (array $fila): array => self::envio($fila, $d), self::lista($datos, 'envios'));

        DB::transaction(function () use ($contratos, $reservas, $cobros, $envios): void {
            $tablas = ['contrato' => $contratos, 'reserva_saldo' => $reservas, 'cobro' => $cobros, 'envio_cobro' => $envios];
            foreach ($tablas as $tabla => $filas) {
                DB::table($tabla)->insertOrIgnore($filas);
                self::comprobarInsertados($tabla, $filas);
            }
        });

        if (isset($this->command)) {
            $this->command->info(sprintf(
                'Finanzas · datos de prueba con D = %s: %d contratos, %d reservas, %d cobros, %d envíos.',
                $d->format('Y-m-d'),
                DB::table('contrato')->count(),
                DB::table('reserva_saldo')->count(),
                DB::table('cobro')->count(),
                DB::table('envio_cobro')->count(),
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

            // 603: cobro ya pagado (historia de demostración); un pagado no cuenta como vencido
            $pagadoEn = isset($fila['pagadoEn']) ? FechaRelativa::fecha(self::texto($fila, 'pagadoEn'), $d) : null;
            if ($pagadoEn !== null) {
                $estado = EstadoCobro::Pagado;
            }

            $resultado[] = [
                'id' => $cobro->id,
                'contrato_id' => $cobro->contratoId,
                'planilla_id' => $cobro->planillaId,
                'numero' => $cobro->numero,
                'monto_neto' => $cobro->montoNeto->aDecimal(),
                'emitido_en' => $cobro->emitidoEn->format('Y-m-d'),
                'vence_en' => $cobro->venceEn->format('Y-m-d'),
                'estado' => $estado->value,
                'pagado_en' => $pagadoEn?->format('Y-m-d'),
                'comprobante' => $pagadoEn === null ? null : self::texto($fila, 'comprobante'),
                'pago_registrado_por' => $pagadoEn === null ? null : self::PAGOS_REGISTRADOS_POR,
                'pago_registrado_en' => $pagadoEn === null ? null : (new DateTimeImmutable(
                    FechaRelativa::resolver(self::texto($fila, 'pagadoEn').' 11:00', $d),
                ))->format('Y-m-d H:i:sP'),
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
     * Fila de `envio_cobro` (201): el correo ya enviado, con su instante en UTC (`D-40 10:05` es la hora de La Paz).
     *
     * @param  array<mixed>  $fila
     * @return array<string, string|null>
     */
    private static function envio(array $fila, DateTimeImmutable $d): array
    {
        $enviadoEn = new DateTimeImmutable(FechaRelativa::resolver(self::texto($fila, 'enviadoEn'), $d));

        return [
            'id' => self::texto($fila, 'id'),
            'cobro_id' => self::texto($fila, 'cobroId'),
            // Falla si el tipo no es uno de los del envío.
            'tipo' => TipoEnvioCobro::from(self::texto($fila, 'tipo'))->value,
            'destinatario' => self::texto($fila, 'destinatario'),
            'enviado_en' => $enviadoEn->format('Y-m-d H:i:sP'),
            'ejecucion_n8n' => null,
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

    /**
     * Los datos de la 011 y, con `$conHistoria` (603), las listas de la historia agregadas a las suyas.
     *
     * @return array<mixed>
     */
    public static function leerDatos(bool $conHistoria = false): array
    {
        $datos = self::leerArchivo(self::ARCHIVO);
        if ($conHistoria) {
            $historia = self::leerArchivo(self::ARCHIVO_HISTORIA);
            foreach (self::LISTAS as $clave) {
                $datos[$clave] = [...self::lista($datos, $clave), ...self::lista($historia, $clave)];
            }
        }

        return $datos;
    }

    /** @return array<mixed> */
    private static function leerArchivo(string $archivo): array
    {
        $texto = file_get_contents($archivo);
        if ($texto === false) {
            throw new RuntimeException('Datos de prueba: no se pudo leer '.$archivo.'.');
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
