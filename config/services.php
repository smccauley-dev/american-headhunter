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

    'dropbox_sign' => [
        'api_key'        => env('DROPBOX_SIGN_API_KEY'),
        'client_id'      => env('DROPBOX_SIGN_CLIENT_ID'),
        'webhook_secret' => env('DROPBOX_SIGN_WEBHOOK_SECRET'),
        'test_mode'      => env('DROPBOX_SIGN_TEST_MODE', true),
    ],

    'clamav' => [
        'enabled' => env('CLAMAV_ENABLED', false),
        'host'    => env('CLAMAV_HOST', '127.0.0.1'),
        'port'    => env('CLAMAV_PORT', 3310),
        'timeout' => env('CLAMAV_TIMEOUT', 30),
    ],

];
