<?php

namespace App\Console\Commands\Telegram;

use Illuminate\Console\Command;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * Shows what Telegram currently holds for this bot.
 *
 * The counterpart to `telegram:set-webhook`, and the only way to diagnose a silent
 * bot from the outside. A wrong path secret shows up here as a URL that does not
 * match this app; a wrong `secret_token` cannot be read back at all, because
 * Telegram never returns it — it surfaces instead as a `last_error_message` of
 * "Wrong response from the webhook: 404 Not Found", which is exactly what our own
 * request-level refusal looks like from Telegram's side. So the last error is the
 * headline output, not a footnote.
 *
 * Neither secret is printed. The registered URL is compared against the one this
 * app would build and reported as a yes/no, which answers the operator's actual
 * question without putting a secret into shell scrollback or CI logs.
 */
class WebhookInfoCommand extends Command
{
    /** @var string */
    protected $signature = 'telegram:webhook-info';

    /** @var string */
    protected $description = 'Show the webhook Telegram currently has registered for this bot';

    public function handle(Api $telegram): int
    {
        try {
            $info = $telegram->getWebhookInfo();
        } catch (TelegramSDKException $e) {
            $this->components->error("Could not ask Telegram: {$e->getMessage()}");

            return self::FAILURE;
        }

        $registered = (string) $info->get('url', '');

        if ($registered === '') {
            $this->components->warn('Telegram has no webhook for this bot. Run `telegram:set-webhook`.');

            return self::FAILURE;
        }

        $allowed = $info->get('allowed_updates', []);
        $matchesThisApp = $registered === route('telegram.webhook', ['token' => config('services.telegram.webhook_secret')]);

        $this->components->twoColumnDetail('Host', (string) parse_url($registered, PHP_URL_HOST));
        $this->components->twoColumnDetail('Matches this app', $matchesThisApp ? 'yes' : 'no');
        $this->components->twoColumnDetail('Pending updates', (string) $info->get('pending_update_count', 0));
        $this->components->twoColumnDetail(
            'Allowed updates',
            $allowed === [] || ! is_array($allowed)
                ? 'all (not restricted)'
                : implode(', ', array_map(strval(...), $allowed)),
        );

        if (! $matchesThisApp) {
            $this->components->warn(
                'Telegram is delivering to a different URL than this app builds. Run `telegram:set-webhook`.'
            );

            return self::FAILURE;
        }

        $lastError = (string) $info->get('last_error_message', '');

        if ($lastError !== '') {
            // A 404 here almost always means the secret token Telegram is sending
            // is not the one this app expects, since that is refused as 404 by
            // design so a probe cannot distinguish it from an unrouted path.
            $this->components->error("Telegram's last delivery failed: {$lastError}");

            return self::FAILURE;
        }

        $this->components->info('Telegram is delivering to this app.');

        return self::SUCCESS;
    }
}
