<?php

namespace App\Console\Commands;

use App\Cobros\Aplicacion\CasosUso\MarcarCobrosVencidos as CasoMarcarCobrosVencidos;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan cobros:marcar-vencidos` (008): pasa a `VENCIDO` los cobros `PENDIENTE` con `vence_en` anterior a hoy en
 * La Paz. El scheduler lo corre cada día a las 00:10 hora de La Paz (routes/console.php).
 */
class MarcarCobrosVencidos extends Command
{
    protected $signature = 'cobros:marcar-vencidos';

    protected $description = 'Marca VENCIDO los cobros PENDIENTE cuyo vencimiento ya pasó (hora de La Paz)';

    public function handle(CasoMarcarCobrosVencidos $marcar): int
    {
        // `CarbonImmutable::now()` respeta `Carbon::setTestNow()` en las pruebas.
        $cantidad = $marcar->ejecutar(CarbonImmutable::now());

        Log::info('Cobros marcados como vencidos.', ['cantidad' => $cantidad]);
        $this->info("Cobros marcados como vencidos: {$cantidad}.");

        return self::SUCCESS;
    }
}
