<?php

namespace App\Services\Telegram\Commands;

use App\Actions\Entitlements\ConsumeEntitlement;
use App\Actions\Entitlements\PurchaseEntitlement;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\EntitlementType;
use App\Enums\PaymentProvider;
use App\Enums\SettingKey;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use App\Services\Telegram\BotButtons;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\Callbacks\ShopCallback;
use App\Services\Telegram\ChannelGatePrompt;
use App\Services\Telegram\HandlesBotCommand;
use Illuminate\Support\Facades\Log;

/**
 * `/shop` — where coins come from, and where they go.
 *
 * Two sections, and the difference between them is the shape of this class.
 * **Coins** are bought with Stars (or Rial) out of a package table, and only on
 * a rail that can take a native payment. **Slots** are bought with coins the
 * user already holds, and that is true on *every* rail.
 *
 * So the rail gates the package section and nothing else. A Bale user whose
 * shelves carry no Rial price is exactly the person who most needs to see the
 * slot section: they can be holding coins from an invite or a completion
 * reward, and a shop that showed them nothing would be a shop that leaves those
 * coins unspendable. Before this, both empty-shelf answers — `no_packages` and
 * `unavailable` — were whole-message early returns, which is why the menu entry
 * promising "Buy coins and slots" had only ever delivered the first half.
 *
 * Nothing here hardcodes a rate (per CLAUDE.md): package prices come from
 * `stars_packages`, slot prices from `PurchaseEntitlement::priceOf()`, and
 * every button names an *index* or a *type* rather than a price — the price is
 * read again when the invoice is created or the slot is charged, so a crafted
 * `callback_data` cannot buy at a stale figure.
 *
 * Gated, because spending is a privileged action in the product's terms.
 */
class ShopCommand implements HandlesBotCommand
{
    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly BotMessenger $messenger,
        private readonly BotButtons $buttons,
        private readonly Settings $settings,
        private readonly PurchaseEntitlement $purchases,
        private readonly ConsumeEntitlement $slots,
        private readonly CoinLedger $ledger,
    ) {}

    public function handle(User $user, BotCommand $command): void
    {
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        [$packages, $packageButtons] = $this->packages($user);
        [$slotSection, $slotButtons] = $this->slotSection($user);

        $buttons = [...$packageButtons, ...$slotButtons];

        $this->messenger->paragraphs(
            $user,
            [
                $this->messenger->line($user, 'bot.shop.prompt'),
                $packages,
                $slotSection,
            ],
            // Null rather than `[]` when there is nothing to sell: the messenger
            // seam reads null as "no keyboard", while an empty array would be
            // sent as `reply_markup: []`, which Telegram refuses.
            $buttons === [] ? null : $buttons,
        );
    }

    /**
     * The coin packages this rail can sell, and their buttons.
     *
     * One package table, two rails: a row is on this payer's shelves only when
     * it carries this rail's price (`stars` for Telegram, `rial` for Bale).
     * Filtering here keeps the button's index meaningful on both rails — it
     * names the row in the shared table, not a position in a per-rail view of
     * it.
     *
     * An empty shelf is a line in the message rather than the message. That is
     * what leaves room for the slot section underneath.
     *
     * @return array{0: string, 1: list<list<array{text: string, callback_data: string}>>}
     */
    private function packages(User $user): array
    {
        if (! $user->platform->supportsNativePayments()) {
            // The rail cannot take a payment at all — so no package is on offer
            // and there is nothing to warn the operator about. The slots below
            // are unaffected: by the time anybody buys one, the money is
            // already in the ledger.
            return [$this->messenger->line($user, 'bot.shop.unavailable'), []];
        }

        $packages = $this->settings->array(SettingKey::StarsPackages);

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

            return [$this->messenger->line($user, 'bot.shop.no_packages'), []];
        }

        return [implode("\n", [
            $this->messenger->line($user, 'bot.shop.packages_heading'),
            ...$lines,
        ]), $buttons];
    }

    /**
     * The slots, priced in coins the user already holds.
     *
     * Deliberately outside the rail guard above — see the class docblock. The
     * slot count is shown here rather than in the greeting because this is
     * where it is actionable: it is the difference between "I need a slot" and
     * "I do not", and it is the only surface where that changes what a user
     * does next.
     *
     * A slot priced below one coin is left out rather than offered.
     * `PurchaseEntitlement` refuses to sell at that price — a free slot is a
     * change to the free allowance, not a purchase — so a button quoting it
     * could only ever fail.
     *
     * @return array{0: string|null, 1: list<list<array{text: string, callback_data: string}>>}
     */
    private function slotSection(User $user): array
    {
        $lines = [];
        $buttons = [];
        $held = [];

        foreach (EntitlementType::cases() as $type) {
            $held[$type->value] = $this->slots->available($user, $type);

            $price = $this->purchases->priceOf($type);

            if ($price < 1) {
                continue;
            }

            $lines[] = $this->messenger->line($user, "bot.shop.slot.{$type->value}", ['coins' => $price]);

            // The very button the refusal path sends. One place knows which
            // callback buys a slot and what it quotes, so the shop and the
            // refusal cannot drift — the price is read once in `priceOf()` and
            // memoised for the rest of the request.
            $buttons[] = [$this->buttons->buySlot($user, $type)];
        }

        if ($lines === []) {
            return [null, []];
        }

        return [implode("\n", [
            $this->messenger->line($user, 'bot.shop.slots_heading', $held),
            ...$lines,
            $this->messenger->line($user, 'bot.slots.balance', [
                'coins' => $this->ledger->balanceFor($user),
            ]),
        ]), $buttons];
    }
}
