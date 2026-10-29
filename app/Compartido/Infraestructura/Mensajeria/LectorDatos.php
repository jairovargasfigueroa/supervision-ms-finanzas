<?php

namespace App\Compartido\Infraestructura\Mensajeria;

use App\Compartido\Dominio\Dinero;
use Illuminate\Support\Str;

/**
 * Lee y valida los campos de `datos` de un sobre. Si un campo falta o no tiene el tipo del contrato de eventos, el
 * mensaje es inválido (va directo a `plataforma.fallidos`). Los textos de error no llevan datos del mensaje.
 */
final class LectorDatos
{
    /** Un uuid obligatorio. */
    public static function uuid(Sobre $sobre, string $campo): string
    {
        $valor = $sobre->datos[$campo] ?? null;
        if (! is_string($valor) || ! Str::isUuid($valor)) {
            throw new MensajeInvalidoException("{$sobre->tipo} sin {$campo} (uuid).");
        }

        return $valor;
    }

    /** Un monto obligatorio y mayor a 0: número o texto numérico, redondeado a 2 decimales. */
    public static function montoPositivo(Sobre $sobre, string $campo): Dinero
    {
        $valor = $sobre->datos[$campo] ?? null;
        if (! is_int($valor) && ! is_float($valor) && ! (is_string($valor) && is_numeric($valor))) {
            throw new MensajeInvalidoException("{$sobre->tipo}: {$campo} no es un número.");
        }
        $monto = Dinero::desde($valor);
        if (! $monto->esPositivo()) {
            throw new MensajeInvalidoException("{$sobre->tipo}: {$campo} debe ser mayor a 0.");
        }

        return $monto;
    }
}
