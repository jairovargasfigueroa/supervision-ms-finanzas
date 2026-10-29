<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'keycloak' => [
        'emisor' => env('KEYCLOAK_ISSUER'),
        'audiencia' => env('KEYCLOAK_AUDIENCIA', 'plataforma-api'),
    ],

    // RabbitMQ (docs/convenciones.md, «Eventos y comandos»): infra declara el exchange, las colas y sus enlaces.
    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'localhost'),
        'puerto' => (int) env('RABBITMQ_PORT', 5672),
        'usuario' => env('RABBITMQ_USER', 'finanzas'),
        'clave' => env('RABBITMQ_PASSWORD', ''),
        'exchange' => 'plataforma',
        'cola' => 'finanzas',
        'cola_fallidos' => 'plataforma.fallidos',
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
