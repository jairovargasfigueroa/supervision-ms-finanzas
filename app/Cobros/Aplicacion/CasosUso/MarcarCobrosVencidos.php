<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Compartido\Dominio\FechaNegocio;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tarea diaria de la 008 (00:10 en La Paz): un cobro `PENDIENTE` pasa a `VENCIDO` al día siguiente de su vencimiento
 * (docs/reglas-negocio.md), es decir, cuando su `vence_en` es anterior a hoy en La Paz. Volver a correrla el mismo día
 * no cambia nada más.
 */
final class MarcarCobrosVencidos
{
    public function __construct(private readonly CobroRepositorio $cobros) {}

    /** @return int cuántos cobros pasó a `VENCIDO` */
    public function ejecutar(DateTimeImmutable $ahora): int
    {
        return DB::transaction(fn () => $this->cobros->marcarVencidos(FechaNegocio::hoy($ahora)));
    }
}
