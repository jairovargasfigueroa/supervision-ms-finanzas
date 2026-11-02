<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 201: correos que n8n envió por cada cobro (docs/datos.md). A lo sumo uno de cada tipo por cobro: el índice único
 * (`cobro_id`, `tipo`) da la idempotencia de los flujos de n8n.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envio_cobro', function (Blueprint $tabla) {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('cobro_id')->constrained('cobro');
            // NOTA_COBRO, RECORDATORIO_POR_VENCER, RECORDATORIO_VENCIDO o CONSTANCIA_PAGO.
            $tabla->string('tipo', 30);
            $tabla->string('destinatario', 254);
            $tabla->timestampTz('enviado_en');
            $tabla->string('ejecucion_n8n', 64)->nullable();
            $tabla->unique(['cobro_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envio_cobro');
    }
};
