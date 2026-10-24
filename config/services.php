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

    'ttlock' => [
        'base_url' => env('TTLOCK_BASE_URL', 'https://euapi.ttlock.com'),
        'client_id' => env('TTLOCK_CLIENT_ID'),
        'client_secret' => env('TTLOCK_CLIENT_SECRET'),
    ],

    'channex' => [
        'base_url' => env('CHANNEX_BASE_URL', 'https://staging.channex.io/api/v1'),
        'api_key' => env('CHANNEX_API_KEY'),
        'group_id' => env('CHANNEX_GROUP_ID'),
        'webhook_secret' => env('CHANNEX_WEBHOOK_SECRET'),
    ],

    'bukku' => [
        'base_url' => env('BUKKU_BASE_URL', 'https://api.bukku.my'),
        'staging_url' => env('BUKKU_STAGING_URL', 'https://api.staging.bukku.dev'),
    ],

    'qne' => [
        'base_url' => env('QNE_BASE_URL', 'https://openapi.account.qne.cloud'),
    ],

    'millionsend' => [
        'base_url' => env('MILLIONSEND_BASE_URL'),
        'api_key' => env('MILLIONSEND_API_KEY'),
        'webhook_secret' => env('MILLIONSEND_WEBHOOK_SECRET'),
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

];
