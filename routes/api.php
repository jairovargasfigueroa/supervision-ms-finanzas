<?php

use App\Compartido\Infraestructura\Auth\MiddlewareToken;
use Illuminate\Support\Facades\Route;

// Todas las rutas exigen token (lo agrega bootstrap/app.php al grupo api); /salud es la única excepción.
Route::get('/salud', fn () => response()->json(['estado' => 'ok']))
    ->withoutMiddleware(MiddlewareToken::class);

// Rutas del negocio: se agregan con cada funcionalidad (006 en adelante) y quedan protegidas sin hacer nada más.

// Ruta de reserva: cualquier otra ruta, con cualquier método, pasa primero por el token y luego responde 404.
Route::any('{ruta}', fn () => abort(404))->where('ruta', '.*')->fallback();
