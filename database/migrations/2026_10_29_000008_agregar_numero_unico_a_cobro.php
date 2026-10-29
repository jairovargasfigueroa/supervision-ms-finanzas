<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 008: el número del cobro (`COB-<año>-<correlativo de 4 dígitos>`, correlativo global por año) es único. El
 * correlativo lo calcula la saga con el mayor número del año; el índice impide repetirlo si dos emisiones chocan.
 * (`planilla_id` ya es único desde la 006: un cobro por planilla.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobro', function (Blueprint $tabla) {
            $tabla->unique('numero');
        });
    }

    public function down(): void
    {
        Schema::table('cobro', function (Blueprint $tabla) {
            $tabla->dropUnique(['numero']);
        });
    }
};
