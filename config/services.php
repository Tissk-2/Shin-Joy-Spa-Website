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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        'booking_notifications_enabled' => env('TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED', false),
        // Parsed, so "1", "on" and "yes" also turn the buttons on: the keyboard checks for a strict true.
        'actions_enabled' => filter_var(env('TELEGRAM_ACTIONS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'staff_chat_id' => env('TELEGRAM_STAFF_CHAT_ID'),
        'message_thread_id' => env('TELEGRAM_MESSAGE_THREAD_ID'),
        'ca_bundle' => env('TELEGRAM_CA_BUNDLE'),
    ],

];
