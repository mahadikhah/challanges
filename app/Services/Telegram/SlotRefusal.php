<?php

namespace App\Services\Telegram;

use App\Actions\Entitlements\PurchaseEntitlement;
use App\Enums\EntitlementType;
use App\Models\User;
use App\Services\CoinLedger;

/**
 * "You have no slot left" — and what one costs, and what you can do about it.
 *
 * The create wizard and the join flow each refused a slot the same way and each
 * said the price without offering a way to pay it, which made the refusal
 * permanent: a user could hold a thousand coins and still never create a second
 * challenge, because nothing in the product would sell them a slot. This is the
 * one place that refusal is now built.
 *
 * **Both halves matter and they are different halves.** The *price* answers "is
 * this worth it?"; the *balance* answers "can I?". Without the second, a user
 * who cannot afford it is left to tap a button that will fail, and one who can
 * afford it does not know it. Both are read at the moment of refusal — they are
 * a `Setting` and a ledger aggregate, so either can change between two messages.
 *
 * The copy keys stay per-scenario (`bot.wizard.*` / `bot.join.*`) rather than
 * collapsing into one shared line, because the two situations are genuinely
 * different sentences — "you have used up your creation slots" is not the same
 * news as "you have used up your joining slots", and the buttons underneath send
 * the reader to different places.
 */
class SlotRefusal
{
    public function __construct(
        private readonly CoinLedger $ledger,
        private readonly BotMessenger $messenger,
        private readonly BotButtons $buttons,
        private readonly PurchaseEntitlement $purchases,
    ) {}

    /**
     * @param  string|null  $joinToken  the challenge they were trying to join, so
     *                                  the purchase can hand them back to it
     */
    public function send(User $user, EntitlementType $type, ?string $joinToken = null): void
    {
        $copy = match ($type) {
            EntitlementType::CreateSlot => 'bot.wizard',
            EntitlementType::JoinSlot => 'bot.join',
        };

        $this->messenger->paragraphs(
            $user,
            [
                $this->messenger->line($user, "{$copy}.no_slot"),
                $this->messenger->line($user, "{$copy}.slot_price", [
                    'coins' => $this->purchases->priceOf($type),
                ]),
                $this->messenger->line($user, 'bot.slots.balance', [
                    'coins' => $this->ledger->balanceFor($user),
                ]),
            ],
            [[$this->buttons->buySlot($user, $type, $joinToken)]],
        );
    }
}
