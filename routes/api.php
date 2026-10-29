<?php

use App\Cobros\Infraestructura\Http\CobroController;
use App\Compartido\Infraestructura\Auth\MiddlewareToken;
use App\Contratos\Infraestructura\Http\ContratoController;
use Illuminate\Support\Facades\Route;

// Todas las rutas exigen token (lo agrega bootstrap/app.php al grupo api); /salud es la única excepción.
Route::get('/salud', fn () => response()->json(['estado' => 'ok']))
    ->withoutMiddleware(MiddlewareToken::class);

// Rutas del negocio: se agregan con cada funcionalidad (006 en adelante) y quedan protegidas sin hacer nada más.

// 006 · Consultar obra y 009 · Consultar tablero: contrato con su saldo, cobros y su resumen, solo para admin y gerente.
Route::middleware('rol:admin,gerente')->group(function () {
    Route::get('/contratos', [ContratoController::class, 'listar']);
    Route::get('/cobros', [CobroController::class, 'listar']);
    // 009 · Consultar tablero. Va antes de `GET /cobros/{id}` para que «resumen» no se tome como id.
    Route::get('/cobros/resumen', [CobroController::class, 'resumen']);
    // 008 · Detalle del cobro de una planilla (lo pide el gateway). Solo acepta un uuid: otro texto cae en el 404.
    Route::get('/cobros/{id}', [CobroController::class, 'mostrar'])->whereUuid('id');
});

// Ruta de reserva: cualquier otra ruta, con cualquier método, pasa primero por el token y luego responde 404.
Route::any('{ruta}', fn () => abort(404))->where('ruta', '.*')->fallback();
