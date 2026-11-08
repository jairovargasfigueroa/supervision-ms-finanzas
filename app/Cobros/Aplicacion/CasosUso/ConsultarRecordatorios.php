<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Aplicacion\RecordatoriosCobros;
use App\Cobros\Dominio\CorteResumen;
use DateTimeImmutable;

/**
 * `GET /cobros/recordatorios` (201, lo llama n8n cada día a las 08:00 de La Paz): los cobros que necesitan
 * recordatorio. Por vencer: `PENDIENTE` que vence entre hoy y hoy + 3 días, sin recordatorio «por vencer» enviado.
 * Vencidos: `VENCIDO`, o `PENDIENTE` con vencimiento anterior a hoy, sin recordatorio «vencido» enviado. Un `PAGADO`
 * no aparece. Las reglas las decide Finanzas; n8n solo envía y anota. Solo lee.
 */
final class ConsultarRecordatorios
{
    /** Días antes del vencimiento en que se recuerda (decisión del equipo). */
    public const DIAS_POR_VENCER = 3;

    public function __construct(private readonly CobroRepositorio $cobros) {}

    public function ejecutar(DateTimeImmutable $ahora): RecordatoriosCobros
    {
        return $this->cobros->paraRecordar(CorteResumen::al($ahora, self::DIAS_POR_VENCER));
    }
}
