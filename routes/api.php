<?php

use Illuminate\Support\Facades\Route;

// Sin token: no toca la base ni RabbitMQ.
Route::get('/salud', fn () => response()->json(['estado' => 'ok']));

// Todo lo demás exige token (docs/contratos.md).
Route::middleware('token')->group(function () {
    // Rutas del negocio: se agregan con cada funcionalidad (006 en adelante).

    // Ruta de reserva: cualquier otra ruta, con cualquier método, pasa primero por el token y luego responde 404.
    Route::any('{ruta}', fn () => abort(404))->where('ruta', '.*')->fallback();
});
