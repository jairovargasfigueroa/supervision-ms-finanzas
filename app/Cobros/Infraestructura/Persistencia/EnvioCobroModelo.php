<?php

namespace App\Cobros\Infraestructura\Persistencia;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `envio_cobro` (docs/datos.md, 201): un correo que n8n envió por un cobro.
 *
 * @property string $id
 * @property string $cobro_id
 * @property string $tipo
 * @property string $destinatario
 * @property CarbonImmutable $enviado_en
 * @property string|null $ejecucion_n8n
 */
class EnvioCobroModelo extends Model
{
    protected $table = 'envio_cobro';

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
            'enviado_en' => 'immutable_datetime',
        ];
    }
}
