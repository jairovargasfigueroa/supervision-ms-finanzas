<?php

namespace App\Contratos\Infraestructura\Mensajeria;

use App\Compartido\Dominio\Dinero;
use App\Compartido\Infraestructura\Mensajeria\ConsumidorBase;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
use App\Contratos\Aplicacion\CasosUso\RegistrarContrato;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * `evento.obras.ObraRegistrada` (002): toma `obraId` y `contrato{monto, plazoDias, anticipoPct, retencionPct, firmadoEn}`.
 *
 * Datos con valores fuera de las reglas (por ejemplo monto 0) se rechazan con `ContratoRechazado`. Datos que faltan o
 * no tienen el formato del contrato de eventos son un mensaje mal formado: lanza y el consumidor lo reintenta y,
 * al tercer fallo, lo manda a `plataforma.fallidos`.
 */
final class ObraRegistradaManejador extends ConsumidorBase
{
    public function __construct(private readonly RegistrarContrato $registrarContrato) {}

    protected function manejar(Sobre $sobre): void
    {
        $obraId = $sobre->datos['obraId'] ?? null;
        if (! is_string($obraId) || ! Str::isUuid($obraId)) {
            throw new InvalidArgumentException('ObraRegistrada sin obraId (uuid).');
        }
        $contrato = $sobre->datos['contrato'] ?? null;
        if (! is_array($contrato)) {
            throw new InvalidArgumentException('ObraRegistrada sin contrato.');
        }

        $resultado = $this->registrarContrato->ejecutar(
            obraId: $obraId,
            monto: Dinero::desde(self::numero($contrato, 'monto')),
            plazoDias: self::entero($contrato, 'plazoDias'),
            anticipoPct: self::numero($contrato, 'anticipoPct'),
            retencionPct: self::numero($contrato, 'retencionPct'),
            firmadoEn: self::fecha($contrato, 'firmadoEn'),
            correlacionId: $sobre->correlacionId,
        );

        Log::info('ObraRegistrada atendida.', ['id' => $sobre->id, 'obraId' => $obraId, 'resultado' => $resultado->name]);
    }

    /**
     * @param  array<mixed>  $contrato
     */
    private static function numero(array $contrato, string $campo): float
    {
        $valor = $contrato[$campo] ?? null;
        if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor))) {
            return (float) $valor;
        }
        throw new InvalidArgumentException("ObraRegistrada: contrato.{$campo} no es un número.");
    }

    /**
     * @param  array<mixed>  $contrato
     */
    private static function entero(array $contrato, string $campo): int
    {
        $valor = self::numero($contrato, $campo);
        if (floor($valor) !== $valor) {
            throw new InvalidArgumentException("ObraRegistrada: contrato.{$campo} no es un entero.");
        }

        return (int) $valor;
    }

    /**
     * @param  array<mixed>  $contrato
     */
    private static function fecha(array $contrato, string $campo): DateTimeImmutable
    {
        $valor = $contrato[$campo] ?? null;
        $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new InvalidArgumentException("ObraRegistrada: contrato.{$campo} no es una fecha AAAA-MM-DD.");
        }

        return $fecha;
    }
}
