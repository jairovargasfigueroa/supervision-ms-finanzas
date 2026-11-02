<?php

namespace App\Cobros\Dominio;

/**
 * Correo que n8n envía por un cobro (201). Un cobro tiene a lo sumo un envío de cada tipo.
 */
enum TipoEnvioCobro: string
{
    /** La nota de cobro en PDF, al emitirse el cobro. */
    case NotaCobro = 'NOTA_COBRO';
    /** Recordatorio de un cobro que vence entre hoy y hoy + 3 días. */
    case RecordatorioPorVencer = 'RECORDATORIO_POR_VENCER';
    /** Recordatorio de un cobro vencido. */
    case RecordatorioVencido = 'RECORDATORIO_VENCIDO';
    /** Constancia al registrar el pago. */
    case ConstanciaPago = 'CONSTANCIA_PAGO';
}
