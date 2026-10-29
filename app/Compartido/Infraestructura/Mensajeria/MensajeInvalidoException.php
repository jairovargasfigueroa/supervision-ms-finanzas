<?php

namespace App\Compartido\Infraestructura\Mensajeria;

use InvalidArgumentException;

/**
 * El mensaje no se puede procesar nunca: no es JSON, el sobre está incompleto o con `version` distinta de 1, faltan
 * datos o su `tipo` no tiene consumidor en esta cola. `mensajeria:consumir` lo manda directo a `plataforma.fallidos`,
 * sin reintentos. El texto no debe llevar datos del mensaje (viaja en `x-error`).
 */
final class MensajeInvalidoException extends InvalidArgumentException {}
