<?php

namespace App\Compartido\Infraestructura\Http;

use App\Compartido\Dominio\ReglaNegocioException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Responde los errores en formato Problem Details (RFC 9457) con `codigo` (docs/convenciones.md, «Errores REST»).
 */
final class ManejadorProblemDetails
{
    /** Código y título genéricos por estado, cuando el error no trae uno propio. */
    private const GENERICOS = [
        400 => ['DATOS_INVALIDOS', 'Datos inválidos'],
        401 => ['NO_AUTENTICADO', 'No autenticado'],
        403 => ['SIN_PERMISO', 'Sin permiso'],
        404 => ['RUTA_NO_ENCONTRADA', 'No encontrado'],
        413 => ['ARCHIVO_DEMASIADO_GRANDE', 'Demasiado grande'],
        429 => ['DEMASIADAS_PETICIONES', 'Demasiadas peticiones'],
        500 => ['ERROR_INTERNO', 'Error interno'],
        503 => ['SERVICIO_NO_DISPONIBLE', 'Servicio no disponible'],
        504 => ['TIEMPO_AGOTADO', 'Tiempo agotado'],
    ];

    public static function registrar(Exceptions $exceptions): void
    {
        // Es un servicio de API: los errores siempre en JSON, nunca páginas HTML ni redirecciones.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        $exceptions->render(fn (AuthenticationException $e) => self::respuesta(401, $e->getMessage()));

        // Regla del negocio: su propio código y estado (por ejemplo `400 PAGINACION_INVALIDA`).
        $exceptions->render(fn (ReglaNegocioException $e) => self::respuesta(
            $e->status,
            $e->getMessage(),
            codigo: $e->codigo,
            titulo: isset(self::GENERICOS[$e->status]) ? null : $e->getMessage(),
        ));

        // Form Request inválido: `400 DATOS_INVALIDOS` con `errores[]` (`campo` y `mensaje`), no el 422 de Laravel.
        $exceptions->render(function (ValidationException $e) {
            $errores = [];
            foreach ($e->errors() as $campo => $mensajes) {
                foreach ($mensajes as $mensaje) {
                    $errores[] = ['campo' => $campo, 'mensaje' => $mensaje];
                }
            }

            return self::respuesta(400, 'Algunos datos no son válidos.', errores: $errores);
        });

        $exceptions->render(function (HttpExceptionInterface $e) {
            $status = $e->getStatusCode();
            if (! isset(self::GENERICOS[$status])) {
                return null;
            }
            // El 404 de una ruta que no existe trae un mensaje técnico en inglés: no se reenvía.
            $detalle = $status === 404 ? 'No existe lo que se busca.' : $e->getMessage();

            return self::respuesta($status, $detalle, $e->getHeaders());
        });
    }

    /**
     * @param  array<string, string>  $encabezados
     * @param  list<array{campo: string, mensaje: string}>|null  $errores
     */
    public static function respuesta(int $status, string $detalle = '', array $encabezados = [], ?string $codigo = null, ?string $titulo = null, ?array $errores = null): JsonResponse
    {
        [$codigoGenerico, $tituloGenerico] = self::GENERICOS[$status] ?? self::GENERICOS[500];
        $titulo ??= $tituloGenerico;

        return new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => $titulo,
                'status' => $status,
                'detail' => $detalle !== '' ? $detalle : $titulo,
                'codigo' => $codigo ?? $codigoGenerico,
            ] + ($errores === null ? [] : ['errores' => $errores]),
            $status,
            ['Content-Type' => 'application/problem+json'] + $encabezados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
