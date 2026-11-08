<?php

namespace App\Compartido\Dominio;

/**
 * Criterios de una lista paginada (docs/convenciones.md, «Listas y paginación»): `pagina` desde 1, `tamano` por defecto
 * 20 y máximo 100, `orden` (campo del JSON) y `direccion` (`asc` o `desc`).
 */
final readonly class CriteriosPagina
{
    public const TAMANO_POR_DEFECTO = 20;

    public const TAMANO_MAXIMO = 100;

    /**
     * @throws ReglaNegocioException `400 PAGINACION_INVALIDA` si `tamano` es mayor a 100
     */
    public function __construct(
        public int $pagina,
        public int $tamano,
        public string $orden,
        public string $direccion,
    ) {
        if ($tamano > self::TAMANO_MAXIMO) {
            throw new ReglaNegocioException(
                'PAGINACION_INVALIDA',
                'Se pueden pedir hasta '.self::TAMANO_MAXIMO.' elementos por página.',
                400,
            );
        }
    }

    public function desplazamiento(): int
    {
        return ($this->pagina - 1) * $this->tamano;
    }
}
