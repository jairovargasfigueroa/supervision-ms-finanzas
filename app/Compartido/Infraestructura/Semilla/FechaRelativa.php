<?php

namespace App\Compartido\Infraestructura\Semilla;

use App\Compartido\Dominio\FechaNegocio;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Fechas relativas de los datos de prueba (011, docs/datos-prueba.md sección 1). Ninguna fecha de la semilla está
 * escrita en duro: se calcula a partir de la fecha de carga D, en la zona del negocio (`America/La_Paz`).
 *
 * Notación del archivo de datos (con D = 2026-10-28):
 * - `D`, `D-120`, `D+18`: fecha → `2026-06-30`, `2026-11-15`.
 * - `D-2 11:00`: ese día a esa hora de La Paz → instante en UTC `2026-10-26T15:00:00Z`.
 * - `M0`, `M-3`, `M+2`: periodo (mes calendario) → `2026-07`.
 * - `M-1/09 10:00`: día 9 del mes `M-1` a esa hora de La Paz → `2026-09-09T14:00:00Z`. El día va de 2 a 27.
 *
 * Una expresión mal formada o un día fuera de 2–27 es un error: no se adivina.
 */
final class FechaRelativa
{
    private const FECHA = '/^D(?:([+-])(\d{1,4}))?$/';

    private const FECHA_HORA = '/^D(?:([+-])(\d{1,4}))? (\d{2}):(\d{2})$/';

    private const PERIODO = '/^M(?:0|([+-])([1-9]\d{0,2}))$/';

    private const DIA_DEL_PERIODO = '/^M(?:0|([+-])([1-9]\d{0,2}))\/(\d{2}) (\d{2}):(\d{2})$/';

    private const DIA_MINIMO = 2;

    private const DIA_MAXIMO = 27;

    /**
     * Fecha de carga D (a las 00:00 de La Paz): la de `SEMILLA_FECHA` (`AAAA-MM-DD`) si viene; si no, la fecha que es
     * hoy en La Paz en el instante `$ahora`.
     */
    public static function fechaDeCarga(?string $semillaFecha, DateTimeImmutable $ahora): DateTimeImmutable
    {
        $texto = trim($semillaFecha ?? '');
        if ($texto === '') {
            return FechaNegocio::hoy($ahora);
        }

        $zona = new DateTimeZone(FechaNegocio::ZONA);
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $texto, $zona)
            : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $texto) {
            throw new InvalidArgumentException("SEMILLA_FECHA «{$texto}» no es una fecha AAAA-MM-DD válida.");
        }

        return $fecha;
    }

    /**
     * Resuelve la expresión con la fecha de carga `$d`: `AAAA-MM-DD` (fecha), `AAAA-MM` (periodo) o
     * `AAAA-MM-DDTHH:MM:SSZ` (instante en UTC).
     */
    public static function resolver(string $expresion, DateTimeImmutable $d): string
    {
        // D es una fecha de calendario: cuenta su día tal como está escrito, a las 00:00 de La Paz.
        $base = new DateTimeImmutable($d->format('Y-m-d'), new DateTimeZone(FechaNegocio::ZONA));

        if (preg_match(self::FECHA, $expresion, $partes) === 1) {
            return self::moverDias($base, $partes)->format('Y-m-d');
        }

        if (preg_match(self::FECHA_HORA, $expresion, $partes) === 1) {
            return self::instante(self::moverDias($base, $partes), $partes[3], $partes[4], $expresion);
        }

        if (preg_match(self::PERIODO, $expresion, $partes) === 1) {
            return self::moverMeses($base, $partes)->format('Y-m');
        }

        if (preg_match(self::DIA_DEL_PERIODO, $expresion, $partes) === 1) {
            $dia = (int) $partes[3];
            if ($dia < self::DIA_MINIMO || $dia > self::DIA_MAXIMO) {
                throw new InvalidArgumentException(
                    "Fecha relativa «{$expresion}»: el día debe ir de ".self::DIA_MINIMO.' a '.self::DIA_MAXIMO.'.',
                );
            }
            $mes = self::moverMeses($base, $partes);

            return self::instante(
                $mes->setDate((int) $mes->format('Y'), (int) $mes->format('m'), $dia),
                $partes[4],
                $partes[5],
                $expresion,
            );
        }

        throw new InvalidArgumentException("Fecha relativa «{$expresion}» mal formada.");
    }

    /**
     * Resuelve una expresión que debe ser una fecha (`D`, `D-n`, `D+n`), para las columnas `date`. Devuelve la fecha a
     * las 00:00 de La Paz.
     */
    public static function fecha(string $expresion, DateTimeImmutable $d): DateTimeImmutable
    {
        if (preg_match(self::FECHA, $expresion) !== 1) {
            throw new InvalidArgumentException("Fecha relativa «{$expresion}»: se esperaba una fecha (D, D-n o D+n).");
        }

        return new DateTimeImmutable(self::resolver($expresion, $d), new DateTimeZone(FechaNegocio::ZONA));
    }

    /**
     * @param  array<int, string>  $partes  signo en [1] y días en [2] (vacíos para `D`)
     */
    private static function moverDias(DateTimeImmutable $base, array $partes): DateTimeImmutable
    {
        if (($partes[1] ?? '') === '') {
            return $base;
        }
        $intervalo = new DateInterval('P'.(int) $partes[2].'D');

        return $partes[1] === '-' ? $base->sub($intervalo) : $base->add($intervalo);
    }

    /**
     * Primer día del mes `M±k` (el que contiene a la base, movido k meses).
     *
     * @param  array<int, string>  $partes  signo en [1] y meses en [2] (vacíos para `M0`)
     */
    private static function moverMeses(DateTimeImmutable $base, array $partes): DateTimeImmutable
    {
        $primero = $base->setDate((int) $base->format('Y'), (int) $base->format('m'), 1);
        if (($partes[1] ?? '') === '') {
            return $primero;
        }
        $intervalo = new DateInterval('P'.(int) $partes[2].'M');

        return $partes[1] === '-' ? $primero->sub($intervalo) : $primero->add($intervalo);
    }

    private static function instante(DateTimeImmutable $dia, string $hora, string $minuto, string $expresion): string
    {
        if ((int) $hora > 23 || (int) $minuto > 59) {
            throw new InvalidArgumentException("Fecha relativa «{$expresion}»: la hora no es válida.");
        }

        return $dia->setTime((int) $hora, (int) $minuto)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
