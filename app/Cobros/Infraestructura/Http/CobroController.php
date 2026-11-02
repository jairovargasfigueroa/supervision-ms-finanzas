<?php

namespace App\Cobros\Infraestructura\Http;

use App\Cobros\Aplicacion\CasosUso\ConsultarCobro;
use App\Cobros\Aplicacion\CasosUso\ListarCobros;
use App\Cobros\Aplicacion\CasosUso\RegistrarPagoCobro;
use App\Cobros\Aplicacion\CasosUso\ResumirCobros;
use App\Compartido\Infraestructura\Auth\UsuarioActual;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cobros al cliente (006) y su resumen para el tablero (009), con roles `admin` y `gerente`; registrar el pago (201),
 * solo `admin`. Los roles se declaran en la ruta.
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

    /**
     * `GET /cobros/{id}` (008): el mismo objeto que en la lista. Un id que no existe responde `404` (el genérico,
     * `RUTA_NO_ENCONTRADA`: el contrato no define un código propio).
     */
    public function mostrar(Request $request, string $id, ConsultarCobro $consultar): JsonResponse
    {
        $cobro = $consultar->ejecutar($id);
        if ($cobro === null) {
            abort(404);
        }

        return response()->json(CobroResource::make($cobro)->resolve($request));
    }

    /** `GET /cobros/resumen` (009): `{ vencidos: { cantidad, monto }, porVencer30Dias: { cantidad, monto } }`. */
    public function resumen(Request $request, ResumirCobros $resumir): JsonResponse
    {
        // `CarbonImmutable::now()` respeta `Carbon::setTestNow()` en las pruebas.
        return response()->json(
            ResumenCobrosResource::make($resumir->ejecutar(CarbonImmutable::now()))->resolve($request),
        );
    }

    /** `POST /cobros/{id}/pago` (201): registra el pago completo y responde `200` con el cobro (como `GET /cobros/{id}`). */
    public function registrarPago(RegistrarPagoRequest $request, string $id, RegistrarPagoCobro $registrar): JsonResponse
    {
        $cobro = $registrar->ejecutar(
            $id,
            $request->pagadoEn(),
            $request->comprobante(),
            UsuarioActual::desde($request)->id,
            CarbonImmutable::now()->toDateTimeImmutable(),
        );

        return response()->json(CobroResource::make($cobro)->resolve($request));
    }
}
