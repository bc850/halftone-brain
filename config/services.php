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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'monday' => [
        'api_url' => env('MONDAY_API_URL', 'https://api.monday.com/v2'),
        'api_version' => env('MONDAY_API_VERSION', '2026-07'),
        'personal_token' => env('MONDAY_PERSONAL_TOKEN'),
        'connect_timeout' => (int) env('MONDAY_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('MONDAY_TIMEOUT', 20),
        'max_response_bytes' => (int) env('MONDAY_MAX_RESPONSE_BYTES', 1048576),
    ],

];
