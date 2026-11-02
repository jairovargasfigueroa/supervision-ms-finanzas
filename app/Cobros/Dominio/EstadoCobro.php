<?php

namespace App\Cobros\Dominio;

/**
 * Estado de un cobro (docs/reglas-negocio.md): `PENDIENTE` al emitirse; `VENCIDO` al día siguiente de su vencimiento;
 * `PAGADO` cuando administración registra su pago (201), desde `PENDIENTE` o `VENCIDO`. Un `PAGADO` no cambia más.
 */
enum EstadoCobro: string
{
    case Pendiente = 'PENDIENTE';
    case Vencido = 'VENCIDO';
    case Pagado = 'PAGADO';
}
