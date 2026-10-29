<?php

namespace App\Compartido\Dominio;

use InvalidArgumentException;

/**
 * Monto en bolivianos redondeado a 2 decimales, «mitad hacia arriba» (docs/convenciones.md). Se guarda en centavos
 * para que las sumas y comparaciones sean exactas.
 */
final readonly class Dinero
{
    private function __construct(public int $centavos) {}

    /**
     * @param  int|float|string  $monto  número o texto numérico (por ejemplo `1250000.00`)
     */
    public static function desde(int|float|string $monto): self
    {
        if (is_string($monto) && ! is_numeric($monto)) {
            throw new InvalidArgumentException("El monto «{$monto}» no es un número.");
        }

        return new self((int) round(round((float) $monto, 2) * 100));
    }

    public function esPositivo(): bool
    {
        return $this->centavos > 0;
    }

    /** Texto con 2 decimales y punto, para guardar en `numeric(14,2)`. */
    public function aDecimal(): string
    {
        $signo = $this->centavos < 0 ? '-' : '';
        $absoluto = abs($this->centavos);

        return sprintf('%s%d.%02d', $signo, intdiv($absoluto, 100), $absoluto % 100);
    }
}
