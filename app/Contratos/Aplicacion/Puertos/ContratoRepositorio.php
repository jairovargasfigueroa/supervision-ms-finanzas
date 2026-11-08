<?php

namespace App\Contratos\Aplicacion\Puertos;

use App\Contratos\Aplicacion\ContratoConsultado;
use App\Contratos\Dominio\Contrato;

interface ContratoRepositorio
{
    /** Id (uuid) para un contrato nuevo. */
    public function siguienteId(): string;

    public function existeParaObra(string $obraId): bool;

    public function guardar(Contrato $contrato): void;

    /**
     * El contrato de la obra con su saldo (suma de cobros y de reservas vigentes), o `null` si no tiene (006).
     *
     * @param  bool  $bloquear  008: bloquea la fila del contrato hasta el fin de la transacción (`FOR UPDATE`) antes de
     *                          sumar, para que dos reservas a la vez no gasten el mismo saldo
     */
    public function consultarPorObra(string $obraId, bool $bloquear = false): ?ContratoConsultado;

    /**
     * Bloquea la fila del contrato hasta el fin de la transacción (008). Quien cambia reservas o cobros del contrato
     * lo bloquea primero, así quien calcula su saldo no ve el cambio a medias.
     */
    public function bloquear(string $contratoId): void;
}
