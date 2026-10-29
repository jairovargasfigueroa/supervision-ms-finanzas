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

        if (is_int($monto)) {
            return new self($monto * 100);
        }

        // Se redondea sobre el texto decimal (el float 10.125 se escribe «10.125»), no sobre el binario, para que
        // la mitad siempre suba.
        $texto = is_float($monto) ? var_export($monto, true) : trim($monto);
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $texto, $partes) !== 1) {
            // Notación científica: montos fuera de cualquier contrato real.
            return new self((int) round((float) $texto * 100));
        }
        $decimales = str_pad($partes[3] ?? '', 3, '0');
        $centavos = (int) $partes[2] * 100 + (int) substr($decimales, 0, 2) + ((int) $decimales[2] >= 5 ? 1 : 0);

        return new self($partes[1] === '-' ? -$centavos : $centavos);
    }

    public function esPositivo(): bool
    {
        return $this->centavos > 0;
    }

    public function sumar(self $otro): self
    {
        return new self($this->centavos + $otro->centavos);
    }

    public function restar(self $otro): self
    {
        return new self($this->centavos - $otro->centavos);
    }

    /** Número para el JSON (hasta 2 decimales; un monto entero como `1180000.00` sale `1180000`). */
    public function aNumero(): float
    {
        return $this->centavos / 100;
    }

    /** Texto con 2 decimales y punto, para guardar en `numeric(14,2)`. */
    public function aDecimal(): string
    {
        $signo = $this->centavos < 0 ? '-' : '';
        $absoluto = abs($this->centavos);

        return sprintf('%s%d.%02d', $signo, intdiv($absoluto, 100), $absoluto % 100);
    }
}
