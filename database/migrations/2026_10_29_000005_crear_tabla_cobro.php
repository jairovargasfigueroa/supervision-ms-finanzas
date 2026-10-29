<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobros al cliente (docs/datos.md). La 006 solo los lee (`GET /cobros` y total cobrado del contrato); los emite la
 * saga de la 008, que reutiliza esta tabla. Un cobro por planilla: `planilla_id` único.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro', function (Blueprint $tabla) {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('contrato_id')->constrained('contrato');
            // Referencia a Obras: solo el id, sin clave foránea.
            $tabla->uuid('planilla_id')->unique();
            $tabla->string('numero');
            $tabla->decimal('monto_neto', 14, 2);
            $tabla->date('emitido_en');
            $tabla->date('vence_en');
            // PENDIENTE o VENCIDO.
            $tabla->string('estado', 20);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro');
    }
};
