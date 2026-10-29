<?php

namespace App\Compartido\Dominio;

/**
 * Una página de una lista: `{ items, total, pagina, tamano }` (docs/convenciones.md, «Listas y paginación»).
 *
 * @template T
 */
final readonly class Pagina
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $pagina,
        public int $tamano,
    ) {}
}
