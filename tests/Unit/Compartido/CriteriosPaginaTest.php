<?php

namespace Tests\Unit\Compartido;

use App\Compartido\Dominio\CriteriosPagina;
use App\Compartido\Dominio\ReglaNegocioException;
use PHPUnit\Framework\TestCase;

/**
 * Paginación de las convenciones: `tamano` hasta 100; más → `400 PAGINACION_INVALIDA`.
 */
class CriteriosPaginaTest extends TestCase
{
    public function test_calcula_el_desplazamiento_de_la_pagina(): void
    {
        $this->assertSame(40, (new CriteriosPagina(3, 20, 'venceEn', 'asc'))->desplazamiento());
        $this->assertSame(0, (new CriteriosPagina(1, 100, 'venceEn', 'asc'))->desplazamiento());
    }

    public function test_tamano_mayor_a_100_es_paginacion_invalida(): void
    {
        try {
            new CriteriosPagina(1, 101, 'venceEn', 'asc');
            $this->fail('Debía rechazar el tamaño.');
        } catch (ReglaNegocioException $e) {
            $this->assertSame('PAGINACION_INVALIDA', $e->codigo);
            $this->assertSame(400, $e->status);
        }
    }
}
