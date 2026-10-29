<?php

namespace App\Cobros\Dominio;

/**
 * Estado de un cobro (docs/reglas-negocio.md): `PENDIENTE` al emitirse; `VENCIDO` al día siguiente de su vencimiento.
 */
enum EstadoCobro: string
{
    case Pendiente = 'PENDIENTE';
    case Vencido = 'VENCIDO';
}
