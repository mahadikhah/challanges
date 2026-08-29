<?php

namespace App\Console\Commands\Telegram;

use App\Models\TelegramUpdate;
use Illuminate\Console\Command;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * Registers this app's webhook with Telegram.
 *
 * This is what defuses the footgun in the webhook's two-secret design. The
 * endpoint accepts a call only if it carries the path secret *and* the
 * `X-Telegram-Bot-Api-Secret-Token` header, and Telegram only sends that header
 * when it was given `secret_token` at registration time. Register by hand with
 * one half and every update 404s: the bot goes silent with nothing in our logs,
 * because the request is refused before it reaches anything that logs. Going
 * through this command is what keeps the two halves in step.
 */
class SetWebhookCommand extends Command
{
    /** @var string */
    protected $signature = 'telegram:set-webhook
                            {--drop-pending-updates : Discard whatever Telegram has queued for us so far}';

    /** @var string */
    protected $description = "Point Telegram at this app's webhook, with both of its secrets";

    public function handle(Api $telegram): int
    {
        $pathSecret = (string) config('services.telegram.webhook_secret');
        $headerSecret = (string) config('services.telegram.webhook_header_secret');

        if ($pathSecret === '' || $headerSecret === '') {
            $this->components->error(
                'Set both TELEGRAM_WEBHOOK_SECRET and TELEGRAM_WEBHOOK_HEADER_SECRET first — the webhook refuses every call while either is missing.'
            );

            return self::FAILURE;
        }

        $url = route('telegram.webhook', ['token' => $pathSecret]);

        if (! str_starts_with($url, 'https://')) {
            $this->components->error(
                "Telegram only delivers to HTTPS webhooks, and APP_URL resolves to a {$this->scheme($url)} URL."
            );

            return self::FAILURE;
        }

        try {
            $telegram->setWebhook([
                'url' => $url,
                'secret_token' => $headerSecret,
                // Ask only for the kinds the router handles. Anything else would
                // be recorded, queued, stamped and dropped — work for no reason.
                'allowed_updates' => TelegramUpdate::HANDLED_KINDS,
                'drop_pending_updates' => (bool) $this->option('drop-pending-updates'),
            ]);
        } catch (TelegramSDKException $e) {
            $this->components->error("Telegram refused the webhook: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info('Webhook registered.');

        // The path secret is part of the URL, so the URL is not printed. An
        // operator who wants to check what Telegram actually holds should run
        // `telegram:webhook-info`, which compares it for them without echoing it.
        $this->components->twoColumnDetail('Host', (string) parse_url($url, PHP_URL_HOST));
        $this->components->twoColumnDetail('Secret token', 'sent by Telegram on every update');
        $this->components->twoColumnDetail('Allowed updates', implode(', ', TelegramUpdate::HANDLED_KINDS));

        return self::SUCCESS;
    }

    private function scheme(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_SCHEME) ?: 'relative');
    }
}
