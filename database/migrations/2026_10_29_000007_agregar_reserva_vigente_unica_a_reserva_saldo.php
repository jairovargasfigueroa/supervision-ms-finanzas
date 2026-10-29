<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 008: una sola reserva vigente (`RESERVADA`) por planilla. Las liberadas y consumidas no cuentan: una planilla
 * observada por la saga puede volver a aprobarse y reservar otra vez. Índice parcial (PostgreSQL y SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX reserva_saldo_planilla_vigente_unique ON reserva_saldo (planilla_id) WHERE estado = 'RESERVADA'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reserva_saldo_planilla_vigente_unique');
    }
};
