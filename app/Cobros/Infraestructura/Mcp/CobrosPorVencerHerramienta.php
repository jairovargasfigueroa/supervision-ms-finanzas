<?php

namespace App\Cobros\Infraestructura\Mcp;

use App\Cobros\Aplicacion\CasosUso\ConsultarCobrosPorVencer;
use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\CobrosPorVencer;
use App\Cobros\Aplicacion\TotalCobros;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `cobros_por_vencer` (501): cobros pendientes que vencen en los próximos días y, si se piden, los vencidos, con
 * totales. Solo lee (`ConsultarCobrosPorVencer`).
 *
 * Los argumentos se validan aquí, antes de llamar al caso de uso (no se consulta la base): tipos estrictos, rangos y
 * sin campos de más. Si no cumplen, el resultado es un error de herramienta `ARGUMENTO_INVALIDO`.
 */
#[IsReadOnly]
#[IsOpenWorld(false)]
class CobrosPorVencerHerramienta extends Tool
{
    /** Cobros máximos por respuesta (501, diseño). */
    public const COBROS_MAXIMOS = 50;

    public const DIAS_POR_DEFECTO = 15;

    public const DIAS_MAXIMOS = 90;

    protected string $name = 'cobros_por_vencer';

    protected string $title = 'Cobros por vencer';

    protected string $description = 'Devuelve los cobros pendientes que vencen en los próximos días y, si se pide, los vencidos, al día, con totales.';

    public function __construct(private readonly ConsultarCobrosPorVencer $consultar) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $argumentos = $request->all();
        $problemas = self::validar($argumentos);
        if ($problemas !== []) {
            return self::error('ARGUMENTO_INVALIDO', implode('; ', array_slice($problemas, 0, 5)));
        }

        $dias = $argumentos['dias'] ?? self::DIAS_POR_DEFECTO;
        $incluirVencidos = $argumentos['incluirVencidos'] ?? true;
        $obraId = $argumentos['obraId'] ?? null;
        \assert(is_int($dias) && is_bool($incluirVencidos) && ($obraId === null || is_string($obraId)));

        // `CarbonImmutable::now()` respeta `Carbon::setTestNow()` en las pruebas.
        $resultado = $this->consultar->ejecutar(
            CarbonImmutable::now(),
            $dias,
            $incluirVencidos,
            $obraId,
            self::COBROS_MAXIMOS,
        );

        return Response::structured(self::salida($resultado));
    }

    /**
     * @param  array<array-key, mixed>  $argumentos
     * @return list<string> problemas, cada uno con su campo
     */
    public static function validar(array $argumentos): array
    {
        $problemas = [];
        foreach (array_keys($argumentos) as $campo) {
            if (! in_array($campo, ['dias', 'incluirVencidos', 'obraId'], true)) {
                $problemas[] = "{$campo}: campo no permitido.";
            }
        }
        if (array_key_exists('dias', $argumentos)) {
            $dias = $argumentos['dias'];
            if (! is_int($dias) || $dias < 1 || $dias > self::DIAS_MAXIMOS) {
                $problemas[] = 'dias: debe ser un entero de 1 a '.self::DIAS_MAXIMOS.'.';
            }
        }
        if (array_key_exists('incluirVencidos', $argumentos) && ! is_bool($argumentos['incluirVencidos'])) {
            $problemas[] = 'incluirVencidos: debe ser true o false.';
        }
        if (array_key_exists('obraId', $argumentos)) {
            $obraId = $argumentos['obraId'];
            if (! is_string($obraId) || ! Str::isUuid($obraId)) {
                $problemas[] = 'obraId: debe ser un UUID.';
            }
        }

        return $problemas;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'dias' => $schema->integer()->min(1)->max(self::DIAS_MAXIMOS)->default(self::DIAS_POR_DEFECTO)
                ->description('Días hacia adelante desde hoy (1 a 90).'),
            'incluirVencidos' => $schema->boolean()->default(true)
                ->description('Incluir los cobros ya vencidos.'),
            'obraId' => $schema->string()->format('uuid')
                ->description('Id de la obra; sin él, todas.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $total = fn () => $schema->object([
            'cantidad' => $schema->integer()->required(),
            'monto' => $schema->number()->required(),
        ])->withoutAdditionalProperties()->required();

        return [
            'hoy' => $schema->string()->format('date')->required(),
            'cobros' => $schema->array()->max(self::COBROS_MAXIMOS)->items(
                $schema->object([
                    'cobroId' => $schema->string()->required(),
                    'numero' => $schema->string()->required(),
                    'obraId' => $schema->string()->required(),
                    'planillaId' => $schema->string()->required(),
                    'montoNeto' => $schema->number()->required(),
                    'emitidoEn' => $schema->string()->format('date')->required(),
                    'venceEn' => $schema->string()->format('date')->required(),
                    'estado' => $schema->string()->enum(['PENDIENTE', 'VENCIDO'])->required(),
                    'diasParaVencer' => $schema->integer()->required(),
                ])->withoutAdditionalProperties(),
            )->required(),
            'totales' => $schema->object([
                'vencidos' => $total(),
                'porVencer' => $total(),
            ])->withoutAdditionalProperties()->required(),
            'truncado' => $schema->boolean()->required(),
        ];
    }

    /**
     * Esquemas de entrada y salida estrictos: sin campos de más en el primer nivel (los anidados lo dicen en
     * `outputSchema`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $herramienta = parent::toArray();
        $herramienta['inputSchema']['additionalProperties'] = false;
        if (isset($herramienta['outputSchema'])) {
            $herramienta['outputSchema']['additionalProperties'] = false;
        }

        return $herramienta;
    }

    /**
     * @return array<string, mixed>
     */
    private static function salida(CobrosPorVencer $resultado): array
    {
        $corte = $resultado->corte;

        return [
            'hoy' => $corte->hoy->format('Y-m-d'),
            'cobros' => array_map(fn (CobroConsultado $cobro) => [
                'cobroId' => $cobro->id,
                'numero' => $cobro->numero,
                'obraId' => $cobro->obraId,
                'planillaId' => $cobro->planillaId,
                'montoNeto' => $cobro->montoNeto->aNumero(),
                'emitidoEn' => $cobro->emitidoEn->format('Y-m-d'),
                'venceEn' => $cobro->venceEn->format('Y-m-d'),
                'estado' => $cobro->estado->value,
                'diasParaVencer' => $corte->diasHasta($cobro->venceEn),
            ], $resultado->cobros),
            'totales' => [
                'vencidos' => self::total($resultado->vencidos),
                'porVencer' => self::total($resultado->porVencer),
            ],
            'truncado' => $resultado->truncado,
        ];
    }

    /** @return array{cantidad: int, monto: float} */
    private static function total(TotalCobros $total): array
    {
        return ['cantidad' => $total->cantidad, 'monto' => $total->monto->aNumero()];
    }

    /** Error de negocio como resultado (`isError: true`) con el JSON `{codigo, mensaje}`. */
    private static function error(string $codigo, string $mensaje): Response
    {
        return Response::error((string) json_encode(
            ['codigo' => $codigo, 'mensaje' => $mensaje],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
