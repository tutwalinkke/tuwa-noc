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


    'identity' => [
        'url' => env('IDENTITY_API_URL', 'https://identity.tuwalink.com/api/v1'),
        'service_token' => env('IDENTITY_SERVICE_TOKEN'),

        // Accounts that exist for machine-to-machine auth (this NOC's own
        // service account, and any future ones), not real humans who
        // should receive alert emails — even though they may technically
        // hold tenant-admin/super-admin roles for API access purposes.
        // Comma-separated in .env; a real bug (noc-service@tuwalink.com
        // receiving and permanently failing to accept bandwidth/device
        // alert emails) was found and fixed by adding this exclusion.
        'alert_excluded_emails' => array_filter(array_map('trim', explode(',', env('ALERT_EXCLUDED_EMAILS', 'noc-service@tuwalink.com')))),
    ],
];
