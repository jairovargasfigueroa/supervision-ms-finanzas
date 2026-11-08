<?php

use App\Compartido\Dominio\FechaNegocio;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 008: cada día a las 00:10 hora de La Paz, los cobros PENDIENTE con vencimiento anterior a hoy pasan a VENCIDO.
// Se ejecuta con `php artisan schedule:work` (desarrollo) o `schedule:run` cada minuto (despliegue). Es idempotente:
// correrla de nuevo el mismo día no cambia nada más.
Schedule::command('cobros:marcar-vencidos')
    ->dailyAt('00:10')
    ->timezone(FechaNegocio::ZONA);
