<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 201: registro del pago de un cobro (docs/datos.md). `estado` (varchar(20)) admite además `PAGADO`; los datos del pago
 * quedan en null mientras el cobro no está pagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobro', function (Blueprint $tabla) {
            // Fecha de pago en La Paz.
            $tabla->date('pagado_en')->nullable();
            $tabla->string('comprobante', 50)->nullable();
            // Referencia a Keycloak (`sub` de quien registró el pago): solo el id, sin clave foránea.
            $tabla->uuid('pago_registrado_por')->nullable();
            $tabla->timestampTz('pago_registrado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cobro', function (Blueprint $tabla) {
            $tabla->dropColumn(['pagado_en', 'comprobante', 'pago_registrado_por', 'pago_registrado_en']);
        });
    }
};
