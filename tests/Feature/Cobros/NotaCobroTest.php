<?php

namespace Tests\Feature\Cobros;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Utilidades\MensajesSaga;
use Tests\Utilidades\TokensPrueba;

/**
 * 201: `POST /cobros/{id}/nota-cobro` (rol `automatizacion`). Con los datos de la planilla que manda n8n devuelve el
 * PDF de la nota de cobro; si no son los del cobro, `422 NOTA_COBRO_INCONSISTENTE`.
 */
class NotaCobroTest extends TestCase
{
    use MensajesSaga;
    use RefreshDatabase;
    use TokensPrueba;

    private string $cobroId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simularKeycloak();
        CarbonImmutable::setTestNow('2026-10-02T14:00:41Z');
        $this->crearContrato('3000000.00');
        $this->cobroId = $this->crearCobro('COB-2026-0004', '330149.10', '2026-10-02', self::PLANILLA_ID);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_devuelve_el_pdf_como_adjunto_con_el_numero_del_cobro(): void
    {
        $respuesta = $this->nota($this->cuerpo())->assertOk();

        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="nota-cobro-COB-2026-0004.pdf"', $respuesta->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $respuesta->getContent());
        $this->assertStringContainsString('%%EOF', (string) $respuesta->getContent());
    }

    public function test_el_pdf_trae_numero_neto_leyenda_y_los_datos_de_la_nota(): void
    {
        $texto = self::textoDelPdf((string) $this->nota($this->cuerpo())->assertOk()->getContent());

        foreach ([
            'Constructora Andina',
            'COB-2026-0004',
            'Bs 330.149,10',
            'Este documento no es una factura',
            'Roberto Justiniano Paz',
            'Edificio Residencial Las Palmeras',
            'septiembre 2026',
            '02/10/2026',
            '01/11/2026',
            'P-03',
            'Hormigón armado en columnas',
            'Fecha de emisión',
            'Bs 134.600,00',
            'Bs 3.365,00',
            'Bs 397.770,00',
            'Bs 39.777,00',
            'Bs 27.843,90',
        ] as $esperado) {
            $this->assertStringContainsString($esperado, $texto, "Falta «{$esperado}» en el PDF.");
        }
        // La línea sin cantidad en el periodo no se muestra.
        $this->assertStringNotContainsString('P-09', $texto);
    }

    public function test_el_nombre_de_la_empresa_sale_de_la_configuracion(): void
    {
        config(['cobranza.empresa_nombre' => 'Empresa de Prueba SRL']);

        $texto = self::textoDelPdf((string) $this->nota($this->cuerpo())->assertOk()->getContent());

        $this->assertStringContainsString('Empresa de Prueba SRL', $texto);
    }

    public function test_no_guarda_nada(): void
    {
        $antes = self::conteos();

        $this->nota($this->cuerpo())->assertOk();

        $this->assertSame($antes, self::conteos());
    }

    public function test_acepta_montos_como_texto_numerico_y_una_diferencia_menor_a_medio_centavo(): void
    {
        $cuerpo = $this->cuerpo();
        $cuerpo['planilla']['neto'] = '330149.104';
        $cuerpo['planilla']['subtotal'] = '397770.00';

        $this->nota($cuerpo)->assertOk();
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $cambiar
     */
    #[DataProvider('inconsistencias')]
    public function test_datos_que_no_coinciden_con_el_cobro_responden_422(callable $cambiar): void
    {
        $this->nota($cambiar($this->cuerpo()))
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'NOTA_COBRO_INCONSISTENTE');
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function inconsistencias(): array
    {
        return [
            'planilla de otro cobro' => [function (array $c) {
                $c['planilla']['id'] = '9a1a0000-0000-4000-8000-000000000099';

                return $c;
            }],
            'obra que no es la del contrato' => [function (array $c) {
                $c['obra']['id'] = '0b1a0000-0000-4000-8000-000000000099';

                return $c;
            }],
            'neto distinto del cobro' => [function (array $c) {
                // Cuadra con sus líneas, pero no es el neto del cobro.
                $c['planilla']['retencion'] = 27843.89;
                $c['planilla']['neto'] = 330149.11;

                return $c;
            }],
            'neto que difiere en más de medio centavo' => [function (array $c) {
                $c['planilla']['neto'] = 330149.106;

                return $c;
            }],
            'suma de las líneas distinta del subtotal' => [function (array $c) {
                $c['planilla']['lineas'][0]['monto'] = 134600.01;

                return $c;
            }],
            'subtotal menos anticipo y retención distinto del neto' => [function (array $c) {
                $c['planilla']['descuentoAnticipo'] = 39777.10;

                return $c;
            }],
        ];
    }

    public function test_compara_los_ids_sin_importar_mayusculas(): void
    {
        $cuerpo = $this->cuerpo();
        $cuerpo['planilla']['id'] = strtoupper(self::PLANILLA_ID);
        $cuerpo['obra']['id'] = strtoupper(self::OBRA_ID);

        $this->nota($cuerpo)->assertOk();
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $cambiar
     */
    #[DataProvider('cuerposInvalidos')]
    public function test_datos_mal_formados_responden_400(callable $cambiar, string $campo): void
    {
        $this->nota($cambiar($this->cuerpo()))
            ->assertStatus(400)
            ->assertJsonPath('codigo', 'DATOS_INVALIDOS')
            ->assertJsonPath('errores.0.campo', $campo);
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function cuerposInvalidos(): array
    {
        return [
            'sin cliente' => [function (array $c) {
                unset($c['cliente']);

                return $c;
            }, 'cliente'],
            'obra sin uuid' => [function (array $c) {
                $c['obra']['id'] = 'obra-1';

                return $c;
            }, 'obra.id'],
            'periodo mal formado' => [function (array $c) {
                $c['planilla']['periodo'] = '2026-13';

                return $c;
            }, 'planilla.periodo'],
            'número de planilla 0' => [function (array $c) {
                $c['planilla']['numero'] = 0;

                return $c;
            }, 'planilla.numero'],
            'sin líneas' => [function (array $c) {
                unset($c['planilla']['lineas']);

                return $c;
            }, 'planilla.lineas'],
            'línea sin monto' => [function (array $c) {
                unset($c['planilla']['lineas'][1]['monto']);

                return $c;
            }, 'planilla.lineas.1.monto'],
            'neto que no es número' => [function (array $c) {
                $c['planilla']['neto'] = 'mucho';

                return $c;
            }, 'planilla.neto'],
        ];
    }

    public function test_un_cobro_que_no_existe_responde_404(): void
    {
        $this->nota($this->cuerpo(), 'c0b00000-0000-4000-8000-0000000000ff')
            ->assertNotFound()
            ->assertJsonPath('codigo', 'COBRO_NO_ENCONTRADO');
        $this->nota($this->cuerpo(), 'no-es-un-id')
            ->assertNotFound()
            ->assertJsonPath('codigo', 'COBRO_NO_ENCONTRADO');
    }

    /**
     * @param  list<string>  $roles
     */
    #[DataProvider('rolesSinAcceso')]
    public function test_los_roles_distintos_de_automatizacion_reciben_403(array $roles, string $azp): void
    {
        $this->nota($this->cuerpo(), null, $roles, $azp)
            ->assertForbidden()
            ->assertJsonPath('codigo', 'SIN_PERMISO');
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function rolesSinAcceso(): array
    {
        return [
            'admin' => [['admin'], 'erp'],
            'gerente' => [['gerente'], 'erp'],
            'gerente de servicio' => [['gerente'], 'ia-reportes'],
            'automatizacion desde el ERP' => [['automatizacion', 'admin'], 'erp'],
        ];
    }

    public function test_sin_token_responde_401(): void
    {
        $this->postJson('/cobros/'.$this->cobroId.'/nota-cobro', $this->cuerpo())->assertUnauthorized();
    }

    /**
     * Cuerpo del ejemplo del diseño: neto 330.149,10 = 397.770,00 − 39.777,00 − 27.843,90.
     *
     * @return array<string, mixed>
     */
    private function cuerpo(): array
    {
        return [
            'cliente' => ['nombre' => 'Roberto Justiniano Paz'],
            'obra' => ['id' => self::OBRA_ID, 'nombre' => 'Edificio Residencial Las Palmeras'],
            'planilla' => [
                'id' => self::PLANILLA_ID,
                'numero' => 3,
                'periodo' => '2026-09',
                'lineas' => [
                    ['codigo' => 'P-03', 'nombre' => 'Hormigón armado en columnas', 'unidad' => 'm3', 'cantidadPeriodo' => 40, 'precioUnitario' => 3365.00, 'monto' => 134600.00],
                    ['codigo' => 'P-04', 'nombre' => 'Losa alivianada', 'unidad' => 'm2', 'cantidadPeriodo' => 1000, 'precioUnitario' => 263.17, 'monto' => 263170.00],
                    ['codigo' => 'P-09', 'nombre' => 'Pintura', 'unidad' => 'm2', 'cantidadPeriodo' => 0, 'precioUnitario' => 45.00, 'monto' => 0],
                ],
                'subtotal' => 397770.00,
                'descuentoAnticipo' => 39777.00,
                'retencion' => 27843.90,
                'neto' => 330149.10,
                'anticipoPct' => 10,
                'retencionPct' => 7,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @param  list<string>  $roles
     */
    private function nota(array $cuerpo, ?string $id = null, array $roles = ['automatizacion', 'gerente'], string $azp = 'n8n'): TestResponse
    {
        return $this->postJson(
            '/cobros/'.($id ?? $this->cobroId).'/nota-cobro',
            $cuerpo,
            ['Authorization' => 'Bearer '.$this->firmarConRoles($roles, $azp)],
        );
    }

    /**
     * Texto de los flujos del PDF (descomprimidos si vienen con FlateDecode), para buscar lo que dice la nota. Con la
     * fuente base Helvetica, dompdf escribe cada texto como cadena literal: `(Bs 330.149,10) Tj`.
     */
    private static function textoDelPdf(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $flujos);
        $texto = '';
        foreach ($flujos[1] as $flujo) {
            $descomprimido = @gzuncompress($flujo);
            $texto .= ($descomprimido === false ? $flujo : $descomprimido)."\n";
        }
        // Solo el contenido de las cadenas literales, sin los escapes de paréntesis y barras.
        preg_match_all('/\((.*?)(?<!\\\\)\)/s', $texto, $cadenas);

        $literal = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], implode(' ', $cadenas[1]));

        // Las fuentes base escriben los acentos en Windows-1252.
        return (string) mb_convert_encoding($literal, 'UTF-8', 'Windows-1252');
    }

    /**
     * @return array<string, int>
     */
    private static function conteos(): array
    {
        return [
            'cobro' => DB::table('cobro')->count(),
            'envio_cobro' => DB::table('envio_cobro')->count(),
            'outbox_evento' => DB::table('outbox_evento')->count(),
        ];
    }
}
