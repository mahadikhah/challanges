<?php

namespace App\Console\Commands\Telegram;

use App\Models\TelegramUpdate;
use App\Services\Telegram\BotCommandMenu;
use Illuminate\Console\Command;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * Points Telegram at this app and tells it what the bot can do.
 *
 * The webhook half defuses the footgun in the endpoint's two-secret design. The
 * endpoint accepts a call only if it carries the path secret *and* the
 * `X-Telegram-Bot-Api-Secret-Token` header, and Telegram only sends that header
 * when it was given `secret_token` at registration time. Register by hand with
 * one half and every update 404s: the bot goes silent with nothing in our logs,
 * because the request is refused before it reaches anything that logs. Going
 * through this command is what keeps the two halves in step.
 *
 * The menu half is what makes the bot's commands discoverable at all — without
 * it there is no menu button, and a user has only the `/start` line to go on. The
 * two live together because they are registered at the same moments, by the same
 * operator, and neither is harmful to repeat (see
 * {@see SetWebhookCommand::registerMenu()}).
 */
class SetWebhookCommand extends Command
{
    /** @var string */
    protected $signature = 'telegram:set-webhook
                            {--drop-pending-updates : Discard whatever Telegram has queued for us so far}';

    /** @var string */
    protected $description = "Point Telegram at this app's webhook, with both of its secrets, and register the command menu";

    public function handle(Api $telegram, BotCommandMenu $menu): int
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

        return $this->registerMenu($telegram, $menu);
    }

    /**
     * Register the command menu, once per language Telegram can scope it to.
     *
     * Here rather than in a command of its own because this is the one command
     * every deploy already runs: the setup guides in `docs/` call
     * `telegram:set-webhook` and then `telegram:webhook-info`, and a menu
     * registered by a third command nobody knows to run is a menu that never
     * appears — a silent failure, which is the kind this command exists to
     * prevent. The two are the same act, telling Telegram who we are, and both
     * are safe to repeat.
     */
    private function registerMenu(Api $telegram, BotCommandMenu $menu): int
    {
        foreach ($menu->registrations() as $set) {
            $params = ['commands' => $set['commands']];

            // Omitted rather than sent as an empty string: no `language_code` is
            // what makes a set the default one, and Telegram reads a present but
            // blank value as a language it cannot match.
            if ($set['language_code'] !== null) {
                $params['language_code'] = $set['language_code'];
            }

            try {
                $telegram->setMyCommands($params);
            } catch (TelegramSDKException $e) {
                $this->components->error(
                    'Telegram refused the command menu for '.($set['language_code'] ?? 'the default').": {$e->getMessage()}"
                );

                return self::FAILURE;
            }
        }

        $this->components->info('Command menu registered.');

        // The order as well as the words. Telegram renders a command list in the
        // order it was given, so this line is the affordance itself, and an
        // operator comparing it against what they see in the app is comparing the
        // right two things.
        $this->components->twoColumnDetail('Commands', implode(', ', $menu->order()));
        $this->components->twoColumnDetail(
            'Menu languages',
            implode(', ', $menu->locales()).', plus a default for the rest',
        );

        return self::SUCCESS;
    }

    private function scheme(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_SCHEME) ?: 'relative');
    }
}
