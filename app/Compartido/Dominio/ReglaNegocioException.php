<?php

namespace App\Compartido\Dominio;

use DomainException;

/**
 * Una regla del negocio no se cumple. `codigo` es el de docs/mensajes.md; `status`, el HTTP con que se respondería.
 */
final class ReglaNegocioException extends DomainException
{
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $status = 422,
    ) {
        parent::__construct($mensaje);
    }
}
