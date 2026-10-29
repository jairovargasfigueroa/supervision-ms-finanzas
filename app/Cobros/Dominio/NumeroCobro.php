<?php

namespace App\Cobros\Dominio;

use InvalidArgumentException;

/**
 * Número del cobro (docs/reglas-negocio.md, «Contrato»): `COB-<año>-<correlativo de 4 dígitos>`, por ejemplo
 * `COB-2026-0007`. El correlativo es global por año (el de la fecha de emisión) y empieza en 1 cada año.
 */
final class NumeroCobro
{
    public const PREFIJO = 'COB-';

    public static function formatear(int $anio, int $correlativo): string
    {
        if ($correlativo < 1) {
            throw new InvalidArgumentException('El correlativo del cobro empieza en 1.');
        }

        return sprintf('%s%04d-%04d', self::PREFIJO, $anio, $correlativo);
    }

    /** Lo que tienen en común los números de un año, por ejemplo `COB-2026-`. */
    public static function prefijoDelAnio(int $anio): string
    {
        return sprintf('%s%04d-', self::PREFIJO, $anio);
    }
}
