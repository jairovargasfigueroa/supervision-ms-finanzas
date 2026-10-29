<?php

namespace App\Contratos\Infraestructura\Persistencia;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `contrato` (docs/datos.md).
 *
 * @property string $id
 * @property string $obra_id
 * @property string $monto
 * @property int $plazo_dias
 * @property string $anticipo_pct
 * @property string $retencion_pct
 * @property string $saldo_disponible
 * @property CarbonImmutable $firmado_en
 */
class ContratoModelo extends Model
{
    protected $table = 'contrato';

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
            'plazo_dias' => 'integer',
            'anticipo_pct' => 'decimal:2',
            'retencion_pct' => 'decimal:2',
            'saldo_disponible' => 'decimal:2',
            'firmado_en' => 'immutable_date',
        ];
    }
}
