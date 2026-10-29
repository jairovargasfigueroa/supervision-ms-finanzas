<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox (docs/convenciones.md): ids de los mensajes ya procesados, para no procesar dos veces el mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensaje_procesado', function (Blueprint $tabla) {
            $tabla->uuid('id_mensaje')->primary();
            $tabla->timestampTz('procesado_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensaje_procesado');
    }
};
