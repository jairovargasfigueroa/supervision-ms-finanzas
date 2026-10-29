<?php

namespace App\Compartido\Infraestructura\Mensajeria;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `outbox_evento` (docs/datos.md): un mensaje pendiente de publicar o ya publicado.
 *
 * @property string $id
 * @property string $tipo clave de enrutamiento
 * @property array<string, mixed> $payload sobre completo
 * @property CarbonImmutable $creado_en
 * @property CarbonImmutable|null $publicado_en
 */
class OutboxEvento extends Model
{
    protected $table = 'outbox_evento';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Guarda el mensaje para publicarlo después. Se llama dentro de la transacción que guarda el dato.
     */
    public static function registrar(string $claveRuteo, Sobre $sobre): self
    {
        return self::query()->create([
            'id' => $sobre->id,
            'tipo' => $claveRuteo,
            'payload' => $sobre->aArreglo(),
            'creado_en' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'creado_en' => 'immutable_datetime',
            'publicado_en' => 'immutable_datetime',
        ];
    }
}
