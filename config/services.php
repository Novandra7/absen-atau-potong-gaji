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

    'waha' => [
        'base_url' => env('WAHA_BASE_URL', 'http://localhost:3000'),
        'api_key' => env('WAHA_API_KEY'),
        'session' => env('WAHA_SESSION', 'default'),
        'webhook_secret' => env('WAHA_WEBHOOK_SECRET'),
        'user_phone' => env('USER_PHONE_NUMBER'),
        'user_phones' => env('USER_PHONE_NUMBERS'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-flash-lite-latest'),
    ],

    'maganghub' => [
        'headless' => env('MAGANGHUB_HEADLESS', true),
        'node_path' => env('NODE_PATH', 'node'),
        'puppeteer_executable_path' => env('PUPPETEER_EXECUTABLE_PATH'),
    ],

];
