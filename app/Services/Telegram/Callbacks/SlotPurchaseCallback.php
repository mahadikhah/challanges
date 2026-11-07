<?php

namespace App\Services\Telegram\Callbacks;

use App\Actions\Entitlements\ConsumeEntitlement;
use App\Actions\Entitlements\PurchaseEntitlement;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\EntitlementType;
use App\Exceptions\InsufficientCoinsException;
use App\Models\User;
use App\Services\Telegram\BotButtons;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\ChannelGatePrompt;
use App\Services\Telegram\HandlesCallback;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Turns a tap on a "buy a slot" button into a paid-for slot.
 *
 * This is the only production caller of `PurchaseEntitlement`. Before it existed
 * the action was fully written, fully tested, and unreachable: both refusal paths
 * quoted a price with no keyboard under it, so the refusal was permanent — the
 * check is an *entitlement count*, not a balance, and no amount of coins could
 * change it. The button and this handler are the missing half.
 *
 * **The button carries no price and no user.** Just the slot type, and for a join
 * the token of the challenge they were trying to join. The price is read from
 * `Setting` at the moment of the tap — the same reader the button's own label
 * came from — so a keyboard sent last week cannot sell a slot at last week's
 * price.
 *
 * Three refusals, and they are deliberately not the same refusal:
 *
 * - **A slot is already held.** Nothing was charged and nothing was bought; this
 *   is the double-tap guard described below.
 * - **Not enough coins.** A real, actionable answer, so it says the price, the
 *   balance and the shortfall, and offers the shop.
 * - **The slot is not priced.** An operator misconfiguration, not a user
 *   problem: logged loudly for the admin and told to the user as "not for sale
 *   right now", with no button, because no button would help.
 */
class SlotPurchaseCallback implements HandlesCallback
{
    /**
     * The action word on a slot-purchase button.
     */
    public const ACTION = 'bs';

    public function __construct(
        private readonly PurchaseEntitlement $purchases,
        private readonly ConsumeEntitlement $slots,
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly BotMessenger $messenger,
        private readonly BotButtons $buttons,
    ) {}

    public function handle(User $user, BotCallback $callback): void
    {
        // Spending coins is a privileged act, and the button outlives the state
        // that produced it — the refusal that carried it may be days old.
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        $type = EntitlementType::tryFrom((string) $callback->argument(0));

        if ($type === null) {
            $this->buttons->stale($user);

            return;
        }

        $joinToken = $callback->argument(1);

        if ($this->alreadyHolding($user, $type, $joinToken)) {
            return;
        }

        $key = $this->idempotencyKey($user, $type, $callback->updateId);

        if ($key === null) {
            // A crafted or legacy payload with no envelope behind it. There is no
            // safe key to fall back on: inventing one (a timestamp, a random) would
            // turn a redelivery into a genuine second charge, which is the exact
            // failure the key exists to prevent. Refuse instead.
            Log::warning('A slot purchase arrived with no update id to key it by.', [
                'user_id' => $user->getKey(),
                'platform' => $user->platform->value,
                'entitlement_type' => $type->value,
            ]);

            $this->buttons->stale($user);

            return;
        }

        try {
            $this->purchases->handle($user, $type, $key);
        } catch (InsufficientCoinsException $short) {
            $this->messenger->paragraphs(
                $user,
                [$this->messenger->line($user, 'bot.slots.short', [
                    'price' => $short->required,
                    'balance' => $short->balance,
                    'short' => $short->shortfall(),
                ])],
                $this->buttons->keyboard($user, 'shop'),
            );

            return;
        } catch (InvalidArgumentException $unpriced) {
            // `PurchaseEntitlement` refuses a price below 1 rather than handing
            // out a free slot, because a zero-coin `CoinPurchase` row would
            // misreport a baseline grant as something paid for. Getting here means
            // an admin set a slot price to 0.
            Log::error('A slot was tapped at a price that cannot be charged.', [
                'user_id' => $user->getKey(),
                'entitlement_type' => $type->value,
                'reason' => $unpriced->getMessage(),
            ]);

            $this->messenger->send($user, $this->messenger->line($user, 'bot.slots.misconfigured'));

            return;
        }

        // A `LogicException` from `PurchaseEntitlement` is deliberately not caught:
        // it means this key already paid for someone else's slot, or for a
        // different type, which is a bug in how the key was built here rather than
        // anything the user did. Swallowing it would hide a real defect and report
        // success for a slot that was never sold.
        $this->say($user, 'bot.slots.bought', $type, $joinToken);
    }

    /**
     * Refuse a purchase the user does not need, without charging for it.
     *
     * **This is what makes a double-tap harmless, and it is not the idempotency
     * key.** Telegram issues a fresh `update_id` for each individual tap, so two
     * taps are two deliveries with two keys and the ledger would happily charge
     * twice. What stops it is that the first tap leaves a slot in hand, and this
     * checks for exactly that before the second one gets as far as the ledger.
     *
     * The trade-off, stated plainly: a user cannot stockpile two slots with two
     * consecutive taps. That is a much smaller loss than charging someone twice
     * for one slot — and it is reversible later by keying on the *button* rather
     * than the delivery, which would need a token in the callback data.
     */
    private function alreadyHolding(User $user, EntitlementType $type, ?string $joinToken): bool
    {
        if ($this->slots->available($user, $type) < 1) {
            return false;
        }

        $this->say($user, 'bot.slots.already_have', $type, $joinToken);

        return true;
    }

    /**
     * A stable key for *this delivery* of this tap.
     *
     * `update_id` is unique per platform and not globally — Telegram and Bale
     * number their updates independently — so the platform is part of the key.
     * Without it, a Telegram delivery and a Bale delivery that happen to share an
     * id would collide, and the second would silently replay the first instead of
     * selling anything.
     */
    private function idempotencyKey(User $user, EntitlementType $type, ?string $updateId): ?string
    {
        if ($updateId === null) {
            return null;
        }

        return "entitlement:{$type->value}:{$user->platform->value}:{$updateId}";
    }

    /**
     * The outcome line, plus whatever the new slot is now good for.
     *
     * A join slot bought from a refusal knows which challenge the user was trying
     * to join, so the reply can offer the join again — a genuine resume rather
     * than a restart, because `JoinChallengeFlow::confirm()` re-resolves the
     * challenge from the token and re-runs its gate and slot checks from scratch.
     * The create case cannot do the same: `CreateChallengeWizard` abandons the
     * draft *before* refusing, so the ten answers are already gone and the only
     * honest offer is to start again.
     */
    private function say(User $user, string $lineKey, EntitlementType $type, ?string $joinToken): void
    {
        if ($type === EntitlementType::JoinSlot && $joinToken !== null) {
            $this->messenger->paragraphs(
                $user,
                [$this->messenger->line($user, $lineKey)],
                [[[
                    'text' => $this->messenger->line($user, 'bot.join.join_button'),
                    'callback_data' => BotCallback::encode(JoinCallback::ACTION, $joinToken),
                ]]],
            );

            return;
        }

        $this->buttons->send($user, $lineKey, 'create');
    }
}
