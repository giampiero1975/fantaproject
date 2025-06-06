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
        'scheme' => 'https',
    ],
    
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    
    // Configurazione per il servizio API delle statistiche dei giocatori
    'player_stats_api' => [
        'default_provider' => env('PLAYER_STATS_API_PROVIDER', 'football_data_org'),
        'providers' => [
            'football_data_org' => [
                'base_url' => env('FOOTBALL_DATA_BASE_URL', 'https://api.football-data.org/v4/'),
                'api_key_name' => env('FOOTBALL_DATA_API_KEY_NAME', 'X-Auth-Token'),
                'api_key' => env('FOOTBALL_DATA_API_KEY'),
                'serie_a_competition_id' => env('FOOTBALL_DATA_SERIE_A_ID', 'SA'), // <-- Chiave importante
                'serie_b_competition_id' => env('FOOTBALL_DATA_SERIE_B_ID', 'SB'),
            ],
        ],
    ],
    
];