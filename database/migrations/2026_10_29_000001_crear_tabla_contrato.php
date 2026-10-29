<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato de una obra (002, docs/datos.md). Un contrato por obra: `obra_id` único.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrato', function (Blueprint $tabla) {
            $tabla->uuid('id')->primary();
            // Referencia a Obras: solo el id, sin clave foránea.
            $tabla->uuid('obra_id')->unique();
            $tabla->decimal('monto', 14, 2);
            $tabla->integer('plazo_dias');
            $tabla->decimal('anticipo_pct', 5, 2);
            $tabla->decimal('retencion_pct', 5, 2);
            $tabla->decimal('saldo_disponible', 14, 2);
            $tabla->date('firmado_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato');
    }
};
