<?php

namespace App\Contratos\Infraestructura\Persistencia;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `reserva_saldo` (docs/datos.md). En la 006 solo se lee; la escribe la saga de la 008.
 *
 * @property string $id
 * @property string $contrato_id
 * @property string $planilla_id
 * @property string $monto
 * @property string $estado
 */
class ReservaSaldoModelo extends Model
{
    protected $table = 'reserva_saldo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
        ];
    }
}
