<?php

namespace App\Services\Telegram\Commands;

use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\PaymentProvider;
use App\Enums\SettingKey;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\Callbacks\ShopCallback;
use App\Services\Telegram\ChannelGatePrompt;
use App\Services\Telegram\HandlesBotCommand;
use Illuminate\Support\Facades\Log;

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

        if (! $user->platform->supportsNativePayments()) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.shop.unavailable'));

            return;
        }

        $packages = $this->settings->array(SettingKey::StarsPackages);

        // One package table, two rails: a row is on this payer's shelves only
        // when it carries this rail's price (`stars` for Telegram, `rial` for
        // Bale). Filtering here keeps the button's index meaningful on both
        // rails — it names the row in the shared table, not a position in a
        // per-rail view of it.
        $provider = PaymentProvider::forPlatform($user->platform);
        $priceKey = $provider->priceKey();

        $lines = [];
        $buttons = [];

        foreach ($packages as $index => $package) {
            $price = is_numeric($package[$priceKey] ?? null) ? (int) $package[$priceKey] : 0;
            $coins = (int) ($package['coins'] ?? 0);

            if ($price <= 0) {
                continue;
            }

            $lines[] = $this->messenger->line($user, $provider->packageLabelKey(), [
                $priceKey => $price,
                'coins' => $coins,
            ]);

            // One row per package: a row of four price buttons is unreadable on
            // the phone the shop is almost always being read on.
            $buttons[] = [[
                'text' => $this->messenger->line($user, 'bot.shop.button', ['coins' => $coins]),
                'callback_data' => BotCallback::encode(ShopCallback::ACTION, (string) $index),
            ]];
        }

        if ($lines === []) {
            // No rows carry this rail's price — an unpriced shelf is as empty
            // as an unconfigured one, and gets the same honest answer. On the
            // Bale rail specifically that is also a degraded shelf next to
            // Telegram's, so the operator gets a log line naming the rail.
            Log::warning('A user opened a shop with no packages priced for their rail.', [
                'user_id' => $user->getKey(),
                'platform' => $user->platform->value,
            ]);

            $this->messenger->send($user, $this->messenger->line($user, 'bot.shop.no_packages'));

            return;
        }

        $this->messenger->paragraphs(
            $user,
            [$this->messenger->line($user, 'bot.shop.prompt'), ...$lines],
            $buttons,
        );
    }
}
