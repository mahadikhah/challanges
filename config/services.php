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

    /*
    |--------------------------------------------------------------------------
    | Bale (platform-specific)
    |--------------------------------------------------------------------------
    |
    | Bale's Bot API is Telegram-shaped (same method names, same update
    | envelopes) served from `https://tapi.bale.ai`. Its webhook offers **no**
    | signature or secret-token mechanism — `setWebhook` accepts only a URL —
    | so `webhook_secret` here is the secret *path segment* of the registered
    | URL and the sole authenticator of an inbound call. It must therefore be
    | long and random: it is the whole gate, not one of two factors as on
    | Telegram.
    |
    | `required_channel` seeds the Bale side of the channel gate
    | (`SettingKey::RequiredChannelBale`); empty until a deployment opens a
    | Bale announcement channel, and the gate reads empty as "cannot confirm".
    |
    */
    'bale' => [
        'bot_token' => env('BALE_BOT_TOKEN'),
        'bot_username' => env('BALE_BOT_USERNAME'),
        'webhook_secret' => env('BALE_WEBHOOK_SECRET'),
        'required_channel' => (string) env('BALE_REQUIRED_CHANNEL', ''),

        /*
        | The wallet payment token from @botfather — NOT the bot token. Bale
        | Pay invoices refuse without it; `WALLET-TEST-1111111111111111`
        | behaves like a real one while moving no money. Empty means Bale
        | Pay is disabled on this deploy, and the shop's Bale shelves are
        | refused loudly rather than issuing invoices that cannot charge.
        */
        'provider_token' => env('BALE_PROVIDER_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Healthcheck ping (§2.10 dead-man's switch)
    |--------------------------------------------------------------------------
    | The one mechanism that can detect total cron failure: an external
    | monitor that alerts when our pings STOP. Unset by default — a deploy
    | without it must behave identically to one where the feature does not
    | exist, so this stays nullable and the heartbeat no-ops when it is.
    |
    */
    'healthcheck' => [
        'ping_url' => env('HEALTHCHECK_PING_URL'),
    ],

];
