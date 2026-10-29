<?php

namespace App\Contratos\Infraestructura\Mensajeria;

use App\Compartido\Dominio\Dinero;
use App\Compartido\Infraestructura\Mensajeria\ConsumidorBase;
use App\Compartido\Infraestructura\Mensajeria\MensajeInvalidoException;
use App\Compartido\Infraestructura\Mensajeria\Sobre;
use App\Contratos\Aplicacion\CasosUso\RegistrarContrato;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * `evento.obras.ObraRegistrada` (002): toma `obraId` y `contrato{monto, plazoDias, anticipoPct, retencionPct, firmadoEn}`.
 *
 * Datos con valores fuera de las reglas (por ejemplo monto 0) se rechazan con `ContratoRechazado`. Datos que faltan o
 * no tienen el formato del contrato de eventos son un mensaje inválido: va directo a `plataforma.fallidos`.
 */
final class ObraRegistradaManejador extends ConsumidorBase
{
    public function __construct(private readonly RegistrarContrato $registrarContrato) {}

    protected function manejar(Sobre $sobre): void
    {
        $obraId = $sobre->datos['obraId'] ?? null;
        if (! is_string($obraId) || ! Str::isUuid($obraId)) {
            throw new MensajeInvalidoException('ObraRegistrada sin obraId (uuid).');
        }
        $contrato = $sobre->datos['contrato'] ?? null;
        if (! is_array($contrato)) {
            throw new MensajeInvalidoException('ObraRegistrada sin contrato.');
        }

        $resultado = $this->registrarContrato->ejecutar(
            obraId: $obraId,
            monto: Dinero::desde(self::numerico($contrato, 'monto')),
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
        return (float) self::numerico($contrato, $campo);
    }

    /**
     * Número tal como llegó (entero, decimal o texto numérico), para redondear el monto sin pasar por float.
     *
     * @param  array<mixed>  $contrato
     */
    private static function numerico(array $contrato, string $campo): int|float|string
    {
        $valor = $contrato[$campo] ?? null;
        if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor))) {
            return $valor;
        }
        throw new MensajeInvalidoException("ObraRegistrada: contrato.{$campo} no es un número.");
    }

    /**
     * @param  array<mixed>  $contrato
     */
    private static function entero(array $contrato, string $campo): int
    {
        $valor = self::numero($contrato, $campo);
        if (floor($valor) !== $valor) {
            throw new MensajeInvalidoException("ObraRegistrada: contrato.{$campo} no es un entero.");
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
            throw new MensajeInvalidoException("ObraRegistrada: contrato.{$campo} no es una fecha AAAA-MM-DD.");
        }

        return $fecha;
    }
}
