<?php

namespace App\Compartido\Infraestructura\Mensajeria;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `mensaje_procesado` (inbox, docs/datos.md).
 *
 * @property string $id_mensaje
 * @property CarbonImmutable $procesado_en
 */
class MensajeProcesado extends Model
{
    protected $table = 'mensaje_procesado';

    protected $primaryKey = 'id_mensaje';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Registra el id si es nuevo. Devuelve false si ya estaba (mensaje repetido).
     * Es atómico: con dos consumidores a la vez, solo uno lo registra.
     */
    public static function registrarSiEsNuevo(string $idMensaje): bool
    {
        return self::query()->insertOrIgnore([
            'id_mensaje' => $idMensaje,
            'procesado_en' => CarbonImmutable::now(),
        ]) === 1;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['procesado_en' => 'immutable_datetime'];
    }
}
