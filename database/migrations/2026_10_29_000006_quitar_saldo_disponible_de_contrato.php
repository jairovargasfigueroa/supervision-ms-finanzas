<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 008: el saldo disponible del contrato se calcula al leer (monto − cobros − reservas vigentes, docs/datos.md); la
 * columna guardada estaba duplicada y nadie la leía, así que se quita. La reserva de la saga bloquea la fila del
 * contrato antes de calcular el saldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato', function (Blueprint $tabla) {
            $tabla->dropColumn('saldo_disponible');
        });
    }

    public function down(): void
    {
        Schema::table('contrato', function (Blueprint $tabla) {
            $tabla->decimal('saldo_disponible', 14, 2)->default(0);
        });
    }
};
