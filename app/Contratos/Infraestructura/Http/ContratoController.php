<?php

namespace App\Contratos\Infraestructura\Http;

use App\Contratos\Aplicacion\CasosUso\ConsultarContrato;
use Illuminate\Http\JsonResponse;

/**
 * Contratos de las obras (006). Roles: `admin` y `gerente` (en la ruta).
 */
final class ContratoController
{
    /** `GET /contratos?obraId=`: arreglo con 0 o 1 contrato. */
    public function listar(ConsultarContratosRequest $request, ConsultarContrato $consultar): JsonResponse
    {
        $contratos = $consultar->ejecutar($request->obraId());

        return response()->json(ContratoResource::collection($contratos)->resolve($request));
    }
}
