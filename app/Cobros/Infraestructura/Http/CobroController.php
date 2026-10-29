<?php

namespace App\Cobros\Infraestructura\Http;

use App\Cobros\Aplicacion\CasosUso\ListarCobros;
use Illuminate\Http\JsonResponse;

/**
 * Cobros al cliente (006). Roles: `admin` y `gerente` (en la ruta).
 */
final class CobroController
{
    /** `GET /cobros?obraId=&estado=`: `{ items, total, pagina, tamano }`, por defecto `venceEn asc`. */
    public function listar(ListarCobrosRequest $request, ListarCobros $listar): JsonResponse
    {
        $pagina = $listar->ejecutar($request->obraId(), $request->estado(), $request->criterios());

        return response()->json([
            'items' => CobroResource::collection($pagina->items)->resolve($request),
            'total' => $pagina->total,
            'pagina' => $pagina->pagina,
            'tamano' => $pagina->tamano,
        ]);
    }
}
