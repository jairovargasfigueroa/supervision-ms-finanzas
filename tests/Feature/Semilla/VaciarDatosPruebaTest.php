<?php

namespace Tests\Feature\Semilla;

use Database\Seeders\DatosPruebaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 011: `php artisan datos-prueba:vaciar` vacía todas las tablas salvo `migrations` y respeta la guarda.
 */
class VaciarDatosPruebaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLAS_DEL_NEGOCIO = ['contrato', 'reserva_saldo', 'cobro', 'outbox_evento', 'mensaje_procesado'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['semilla.fecha' => '2026-10-28']);
        $this->seed(DatosPruebaSeeder::class);
        // Algo en el outbox y en el inbox, como si hubiera corrido una saga en la demostración.
        DB::table('outbox_evento')->insert([
            'id' => 'aa000000-0000-4000-8000-000000000001',
            'tipo' => 'evento.finanzas.CobroEmitido',
            'payload' => '{}',
            'creado_en' => '2026-10-28 10:00:00',
        ]);
        DB::table('mensaje_procesado')->insert([
            'id_mensaje' => 'aa000000-0000-4000-8000-000000000002',
            'procesado_en' => '2026-10-28 10:00:00',
        ]);
    }

    public function test_vacia_las_tablas_del_negocio_y_conserva_las_migraciones(): void
    {
        $migraciones = DB::table('migrations')->count();
        $this->assertGreaterThan(0, $migraciones);

        $this->artisan('datos-prueba:vaciar')
            ->expectsOutputToContain('Finanzas · tablas vaciadas')
            ->assertSuccessful();

        foreach (self::TABLAS_DEL_NEGOCIO as $tabla) {
            $this->assertSame(0, DB::table($tabla)->count(), "La tabla {$tabla} debería quedar vacía.");
        }
        $this->assertSame($migraciones, DB::table('migrations')->count());
    }

    public function test_despues_de_vaciar_se_puede_volver_a_cargar(): void
    {
        $this->artisan('datos-prueba:vaciar')->assertSuccessful();
        $this->seed(DatosPruebaSeeder::class);

        $this->assertSame(3, DB::table('contrato')->count());
        $this->assertSame(3, DB::table('reserva_saldo')->count());
        $this->assertSame(3, DB::table('cobro')->count());
    }

    public function test_vaciar_dos_veces_no_falla(): void
    {
        $this->artisan('datos-prueba:vaciar')->assertSuccessful();
        $this->artisan('datos-prueba:vaciar')->assertSuccessful();

        $this->assertSame(0, DB::table('contrato')->count());
    }

    public function test_se_niega_en_produccion_y_no_borra_nada(): void
    {
        config(['app.env' => 'production']);

        $this->artisan('datos-prueba:vaciar')
            ->expectsOutputToContain('no se ejecuta')
            ->assertFailed();

        $this->assertSame(3, DB::table('contrato')->count());
    }

    public function test_se_niega_con_una_base_que_no_es_local_y_no_borra_nada(): void
    {
        // Solo cambia la configuración que lee la guarda; la conexión abierta sigue siendo la de las pruebas.
        $original = config('database.default');
        config([
            'database.default' => 'remota',
            'database.connections.remota' => [
                'driver' => 'pgsql',
                'host' => 'finanzas.abc123.us-east-1.rds.amazonaws.com',
            ],
        ]);

        try {
            $this->artisan('datos-prueba:vaciar')
                ->expectsOutputToContain('no es local')
                ->assertFailed();
        } finally {
            // RefreshDatabase deshace la transacción en la conexión por defecto al terminar.
            config(['database.default' => $original]);
        }

        $this->assertSame(3, DB::table('contrato')->count());
    }
}
