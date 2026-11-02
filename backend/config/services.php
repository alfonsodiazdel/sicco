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

    // CU07 Leer guía con OCR. «fake» no llama a ninguna API (desarrollo y pruebas).
    'ocr' => [
        'driver' => env('OCR_DRIVER', 'fake'),
        // CU07 flujo alternativo 1: sin respuesta en 20 segundos, «Lectura no disponible».
        'timeout' => (float) env('OCR_TIMEOUT', 20),
        // Por debajo de esta confianza el campo se marca para revisión (CU07 paso 4).
        'umbral_confianza' => (float) env('OCR_UMBRAL_CONFIANZA', 0.8),
        // Lecturas por minuto y usuario: cada una cuesta en la API de IA.
        'por_minuto' => (int) env('OCR_POR_MINUTO', 20),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'ocr_model' => env('ANTHROPIC_OCR_MODEL', 'claude-opus-5-5'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
