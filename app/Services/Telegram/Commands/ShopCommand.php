<?php

namespace App\Services\Telegram\Commands;

use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\SettingKey;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\Callbacks\ShopCallback;
use App\Services\Telegram\ChannelGatePrompt;
use App\Services\Telegram\HandlesBotCommand;

/**
 * `/shop` — the coin top-up counter.
 *
 * Lists the admin-tuned Stars packages, nothing more: the prices come from the
 * `stars_packages` setting rather than this file (no rate is hardcoded, per
 * CLAUDE.md), and every button names is a package *index* — the price itself is
 * read again from the setting when the invoice is created, so a crafted
 * `callback_data` cannot buy at a stale price.
 *
 * Gated, because spending Stars is a privileged action in the product's terms.
 */
class ShopCommand implements HandlesBotCommand
{
    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly BotMessenger $messenger,
        private readonly Settings $settings,
    ) {}

    public function handle(User $user, BotCommand $command): void
    {
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        $packages = $this->settings->array(SettingKey::StarsPackages);

        if ($packages === []) {
            // An empty table is a misconfiguration, and the worst thing to do
            // with one is show a shop with nothing on the shelves.
            $this->messenger->send($user, $this->messenger->line($user, 'bot.shop.no_packages'));

            return;
        }

        $lines = [];
        $buttons = [];

        foreach ($packages as $index => $package) {
            $stars = (int) ($package['stars'] ?? 0);
            $coins = (int) ($package['coins'] ?? 0);

            $lines[] = $this->messenger->line($user, 'bot.shop.package', [
                'stars' => $stars,
                'coins' => $coins,
            ]);

            // One row per package: a row of four price buttons is unreadable on
            // the phone the shop is almost always being read on.
            $buttons[] = [[
                'text' => $this->messenger->line($user, 'bot.shop.button', ['coins' => $coins]),
                'callback_data' => BotCallback::encode(ShopCallback::ACTION, (string) $index),
            ]];
        }

        $this->messenger->paragraphs(
            $user,
            [$this->messenger->line($user, 'bot.shop.prompt'), ...$lines],
            $buttons,
        );
    }
}
