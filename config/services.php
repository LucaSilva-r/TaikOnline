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

    // Waddamburo's scorer (the game binary's --rescore mode): plays are scored from their replays with it.
    'waddamburo' => [
        'scorer' => env('WADDAMBURO_SCORER'),
        // The private analyzer: read when it runs, so replacing the file needs no deploy.
        'analyzer' => env('WADDAMBURO_ANALYZER', storage_path('analyzer/wdb-analyze')),
    ],

    // The relay for Waddamburo's online play (relay/), for players who can't connect directly. RELAY_URL: its WebSocket
    // address, through the Cloudflare tunnel. RELAY_SECRET: shared with the relay service. Unset: no relay.
    'relay' => [
        'url' => env('RELAY_URL'),
        'secret' => env('RELAY_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
