<?php

namespace App\Compartido\Infraestructura\Mensajeria;

use Illuminate\Support\Str;
use JsonException;

/**
 * Sobre común de todo mensaje (docs/convenciones.md, «Eventos y comandos»), en JSON camelCase.
 */
final readonly class Sobre
{
    public const ORIGEN = 'finanzas';

    /**
     * @param  array<string, mixed>  $datos
     */
    public function __construct(
        public string $id,
        public string $tipo,
        public int $version,
        public string $ocurridoEn,
        public string $origen,
        public string $correlacionId,
        public array $datos,
    ) {}

    /**
     * Sobre nuevo de este servicio. Al responder a un mensaje se pasa su `correlacionId`.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function nuevo(string $tipo, array $datos, string $correlacionId): self
    {
        return new self(
            id: (string) Str::uuid(),
            tipo: $tipo,
            version: 1,
            ocurridoEn: gmdate('Y-m-d\TH:i:s\Z'),
            origen: self::ORIGEN,
            correlacionId: $correlacionId,
            datos: $datos,
        );
    }

    /**
     * @throws MensajeInvalidoException si el cuerpo no es un sobre válido (no es JSON, falta un campo o `version` no es 1)
     */
    public static function desdeJson(string $cuerpo): self
    {
        try {
            $sobre = json_decode($cuerpo, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MensajeInvalidoException('El mensaje no es JSON válido: '.$e->getMessage(), previous: $e);
        }
        if (! is_array($sobre)) {
            throw new MensajeInvalidoException('El mensaje no es un objeto JSON.');
        }

        $id = $sobre['id'] ?? null;
        $tipo = $sobre['tipo'] ?? null;
        $correlacionId = $sobre['correlacionId'] ?? null;
        $datos = $sobre['datos'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new MensajeInvalidoException('El sobre no tiene un id (uuid) válido.');
        }
        if (! is_string($tipo) || $tipo === '') {
            throw new MensajeInvalidoException('El sobre no tiene tipo.');
        }
        if (! is_string($correlacionId) || $correlacionId === '') {
            throw new MensajeInvalidoException('El sobre no tiene correlacionId.');
        }
        if (! is_array($datos) || ($datos !== [] && array_is_list($datos))) {
            throw new MensajeInvalidoException('El sobre no tiene datos (objeto).');
        }
        if (($sobre['version'] ?? null) !== 1) {
            throw new MensajeInvalidoException('El sobre no es de la versión 1.');
        }

        /** @var array<string, mixed> $datos */
        return new self(
            id: $id,
            tipo: $tipo,
            version: 1,
            ocurridoEn: is_string($sobre['ocurridoEn'] ?? null) ? $sobre['ocurridoEn'] : '',
            origen: is_string($sobre['origen'] ?? null) ? $sobre['origen'] : '',
            correlacionId: $correlacionId,
            datos: $datos,
        );
    }

    /**
     * @return array{id: string, tipo: string, version: int, ocurridoEn: string, origen: string, correlacionId: string, datos: array<string, mixed>}
     */
    public function aArreglo(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'version' => $this->version,
            'ocurridoEn' => $this->ocurridoEn,
            'origen' => $this->origen,
            'correlacionId' => $this->correlacionId,
            'datos' => $this->datos,
        ];
    }

    /**
     * @param  array<mixed>  $arreglo
     */
    public static function json(array $arreglo): string
    {
        return json_encode($arreglo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
