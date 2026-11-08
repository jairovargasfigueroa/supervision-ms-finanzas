<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas de saldo de un contrato (docs/datos.md). La 006 solo las lee (saldo disponible y total reservado); las
 * crea, libera y consume la saga de la 008, que reutiliza esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_saldo', function (Blueprint $tabla) {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('contrato_id')->constrained('contrato');
            // Referencia a Obras: solo el id, sin clave foránea.
            $tabla->uuid('planilla_id');
            $tabla->decimal('monto', 14, 2);
            // RESERVADA, LIBERADA o CONSUMIDA.
            $tabla->string('estado', 20);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_saldo');
    }
};
