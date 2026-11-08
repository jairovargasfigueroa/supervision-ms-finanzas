<?php

/*
 * Datos de prueba (011, docs/datos-prueba.md). Solo en desarrollo: la semilla y el vaciado se niegan con
 * APP_ENV=production o con una base que no es local (GuardaDatosPrueba). 601: con SEMILLA_DEMO=si la carga corre igual
 * (datos de demostración); el vaciado, nunca.
 */
return [

    // Fecha de carga D (AAAA-MM-DD). La calcula una sola vez el script de infra y la pasa a los tres servicios; vacía,
    // es la fecha de hoy en America/La_Paz.
    'fecha' => env('SEMILLA_FECHA'),

    // Permiso explícito para cargar los datos de demostración fuera de desarrollo (601): solo el valor «si».
    'demo' => strtolower(trim((string) env('SEMILLA_DEMO', ''))) === 'si',

];
