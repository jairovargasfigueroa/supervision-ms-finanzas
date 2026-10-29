<?php

namespace App\Console\Commands;

use App\Compartido\Infraestructura\Semilla\GuardaDatosPrueba;
use App\Compartido\Infraestructura\Semilla\VaciadorTablas;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `php artisan datos-prueba:vaciar` (011): vacía todas las tablas de la base salvo `migrations`, para volver a cargar los
 * datos de prueba (`recargar` de infra, que pide la confirmación). Con la misma guarda que la semilla: nunca en
 * producción ni con una base que no es local.
 */
class VaciarDatosPrueba extends Command
{
    protected $signature = 'datos-prueba:vaciar';

    protected $description = 'Vacía las tablas de Finanzas (salvo migrations) para recargar los datos de prueba (solo desarrollo)';

    public function handle(VaciadorTablas $vaciador): int
    {
        try {
            GuardaDatosPrueba::comprobar();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tablas = $vaciador->vaciar();
        $this->info('Finanzas · tablas vaciadas ('.count($tablas).'): '.implode(', ', $tablas).'.');

        return self::SUCCESS;
    }
}
