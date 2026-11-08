<?php

namespace Tests\Utilidades;

use App\Compartido\Infraestructura\Mensajeria\CanalMensajes;

/**
 * Doble en memoria del broker: las pruebas no necesitan RabbitMQ.
 *
 * Guarda lo publicado y simula las colas: lo que se publica en el exchange por defecto (`''`) entra a la cola cuyo
 * nombre es la clave, y `consumir` entrega los mensajes de la cola hasta vaciarla (incluidos los reintentos).
 */
final class CanalMensajesFalso implements CanalMensajes
{
    /** @var list<array{exchange: string, claveRuteo: string, cuerpo: string, idMensaje: string, encabezados: array<string, string|int>}> */
    public array $publicados = [];

    /** Veces que se entregó un mensaje a quien consume. */
    public int $entregas = 0;

    /** @var array<string, list<array{cuerpo: string, claveRuteo: string, encabezados: array<string, mixed>}>> */
    private array $colas = [];

    /**
     * Deja un mensaje en la cola como si hubiera llegado por el exchange `plataforma`.
     *
     * @param  array<string, mixed>  $encabezados
     */
    public function encolar(string $cola, string $cuerpo, string $claveRuteo, array $encabezados = []): void
    {
        $this->colas[$cola][] = ['cuerpo' => $cuerpo, 'claveRuteo' => $claveRuteo, 'encabezados' => $encabezados];
    }

    public function publicar(string $exchange, string $claveRuteo, string $cuerpo, string $idMensaje, array $encabezados = []): void
    {
        $this->publicados[] = compact('exchange', 'claveRuteo', 'cuerpo', 'idMensaje', 'encabezados');
        if ($exchange === '') {
            $this->encolar($claveRuteo, $cuerpo, $claveRuteo, $encabezados);
        }
    }

    public function consumir(string $cola, callable $alRecibir): void
    {
        $this->colas[$cola] ??= [];
        while (($mensaje = array_shift($this->colas[$cola])) !== null) {
            $this->entregas++;
            $alRecibir($mensaje['cuerpo'], $mensaje['claveRuteo'], $mensaje['encabezados']);
        }
    }

    public function cerrar(): void {}

    /**
     * @return list<array{exchange: string, claveRuteo: string, cuerpo: string, idMensaje: string, encabezados: array<string, string|int>}>
     */
    public function publicadosEn(string $exchange, string $claveRuteo): array
    {
        return array_values(array_filter(
            $this->publicados,
            fn (array $p) => $p['exchange'] === $exchange && $p['claveRuteo'] === $claveRuteo,
        ));
    }
}
