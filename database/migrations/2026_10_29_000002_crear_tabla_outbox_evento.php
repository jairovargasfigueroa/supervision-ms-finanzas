<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox (docs/convenciones.md): el evento se guarda en la misma transacción que el dato y
 * `mensajeria:publicar` lo envía a RabbitMQ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_evento', function (Blueprint $tabla) {
            // Es el `id` del sobre: RabbitMQ lo recibe como message_id.
            $tabla->uuid('id')->primary();
            // Clave de enrutamiento, por ejemplo `evento.finanzas.ContratoRegistrado`.
            $tabla->string('tipo');
            // Sobre completo del mensaje.
            $tabla->jsonb('payload');
            $tabla->timestampTz('creado_en');
            $tabla->timestampTz('publicado_en')->nullable();
            // El publicador busca los pendientes en orden de creación.
            $tabla->index(['publicado_en', 'creado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_evento');
    }
};
