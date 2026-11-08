<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Aplicacion\CobroConsultado;
use App\Cobros\Aplicacion\Puertos\CobroRepositorio;

/**
 * `GET /cobros/{id}` (008): un cobro con el mismo formato que la lista, o `null` si no existe. Solo lee.
 */
final class ConsultarCobro
{
    public function __construct(private readonly CobroRepositorio $cobros) {}

    public function ejecutar(string $id): ?CobroConsultado
    {
        return $this->cobros->buscar($id);
    }
}
