<?php

namespace App\Cobros\Infraestructura\Pdf;

use App\Compartido\Dominio\Dinero;
use DateTimeImmutable;

/**
 * Cómo se muestran montos, fechas y periodos al usuario (docs/convenciones.md): `Bs 330.149,10`, `28/04/2027` y
 * `septiembre 2026`.
 */
final class FormatoBolivia
{
    private const MESES = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    public static function monto(Dinero $monto): string
    {
        return 'Bs '.self::numero($monto->aNumero(), 2);
    }

    /** Número con punto de miles y coma decimal; sin ceros de más a la derecha si `$decimales` es null. */
    public static function numero(float $valor, ?int $decimales = null): string
    {
        if ($decimales !== null) {
            return number_format($valor, $decimales, ',', '.');
        }
        $texto = number_format($valor, 4, ',', '.');

        return rtrim(rtrim($texto, '0'), ',');
    }

    public static function fecha(DateTimeImmutable $fecha): string
    {
        return $fecha->format('d/m/Y');
    }

    /** `2026-09` → `septiembre 2026`. */
    public static function periodo(string $periodo): string
    {
        [$anio, $mes] = array_map('intval', explode('-', $periodo, 2) + [1 => '1']);

        return (self::MESES[$mes] ?? $periodo).' '.$anio;
    }
}
