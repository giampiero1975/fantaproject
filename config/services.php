<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'football_data' => [
        'key' => env('FOOTBALL_DATA_API_KEY'),
        'base_uri' => env('FOOTBALL_DATA_API_BASE_URI', 'https://api.football-data.org/v4/'),
        'serie_a_code' => 'SA',
        'serie_b_code' => 'SB', // Aggiungi questo se conosci il codice per la Serie B
        'api_delay_seconds' => env('FOOTBALL_DATA_API_DELAY_SECONDS', 7), // Delay tra chiamate API in batch
    ],

];
