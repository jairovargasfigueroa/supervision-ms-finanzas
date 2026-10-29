<?php

namespace App\Cobros\Infraestructura\Persistencia;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `cobro` (docs/datos.md). En la 006 solo se lee; la escribe la saga de la 008.
 *
 * @property string $id
 * @property string $contrato_id
 * @property string $planilla_id
 * @property string $numero
 * @property string $monto_neto
 * @property CarbonImmutable $emitido_en
 * @property CarbonImmutable $vence_en
 * @property string $estado
 * @property string $obra_id solo al leer con el contrato unido (`obra_id` de `contrato`)
 */
class CobroModelo extends Model
{
    protected $table = 'cobro';

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
            'monto_neto' => 'decimal:2',
            'emitido_en' => 'immutable_date',
            'vence_en' => 'immutable_date',
        ];
    }
}
