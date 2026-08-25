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

    /*
    |--------------------------------------------------------------------------
    | Telegram (platform-specific)
    |--------------------------------------------------------------------------
    |
    | The telegram-bot-sdk publishes its own `config/telegram.php` (bot token,
    | HTTP client, etc.). This block holds the *platform* keys the SDK config
    | does not cover — the webhook secret, required announcement channel, Mini
    | App URL, and Telegram's Ed25519 public keys for third-party initData
    | verification. `bot_token` is mirrored here so all platform code can read a
    | single `services.telegram.*` namespace; it derives from the same env var
    | the SDK uses. Runtime-tunable values (token TTLs, required channel) may
    | later be overridden by the admin `Setting` store.
    |
    */
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'webhook_header_secret' => env('TELEGRAM_WEBHOOK_HEADER_SECRET'),
        'required_channel' => (string) env('TELEGRAM_REQUIRED_CHANNEL', ''),
        'miniapp_url' => env('MINIAPP_URL'),
        'initdata_ttl' => (int) env('TELEGRAM_INITDATA_TTL', 3600),
        'ed25519_public_keys' => [
            'production' => env(
                'TELEGRAM_ED25519_PUBLIC_KEY_PRODUCTION',
                'e7bf03a2fa4602af4580703d88dda5bb59f32ed8b02a56c187fe7d34caed242d',
            ),
            'test' => env(
                'TELEGRAM_ED25519_PUBLIC_KEY_TEST',
                '40055058a4ee38156a06562e52eece92a771bcd8346a8c4615cb7376eddf72ec',
            ),
        ],
    ],

];
