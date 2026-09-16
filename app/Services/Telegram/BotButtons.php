<?php

namespace App\Services\Telegram;

use App\Actions\Entitlements\PurchaseEntitlement;
use App\Enums\EntitlementType;
use App\Models\Challenge;
use App\Models\User;
use App\Services\Telegram\Callbacks\CheckInCallback;
use App\Services\Telegram\Callbacks\CommandCallback;
use App\Services\Telegram\Callbacks\SlotPurchaseCallback;

/**
 * The buttons that go under a bot message, built in one place.
 *
 * The bot used to answer a dead end by naming a command — "send /create to start
 * a challenge" — which is an instruction only a user who already knows the
 * command can follow. Every one of those lines now carries the thing it was
 * describing instead, and this is where that thing is built.
 *
 * **A command button's label is the command menu's description**, read from the
 * same `bot.commands.*` keys Task 5 registers with Telegram. One source, so the
 * word under the message button and the word under the menu button cannot drift
 * apart — and a user who taps a button learns a command's name for free, which is
 * how the menu becomes discoverable rather than redundant.
 *
 * The two kinds here are the two a *command* can be delivered by: a command word
 * (dispatched through `CommandRouter` by {@see CommandCallback}, so a tap and a
 * typed command end in the same handler) and a challenge's check-in token (the
 * `ci:` button the check-in listing, the proof review and the reminders all use).
 */
class BotButtons
{
    public function __construct(
        private readonly BotMessenger $messenger,
        private readonly BotCommandMenu $menu,
        private readonly PurchaseEntitlement $purchases,
    ) {}

    /**
     * One translated line, with a row of command buttons under it.
     *
     * Used where the message is a verdict rather than a question: the button is
     * the only thing to do next, so it travels with the sentence that explains
     * why. Messages that already carry a keyboard — a wizard question, a shop
     * shelf — add their own buttons instead and call `row()`.
     */
    public function send(User $user, string $line, string ...$commands): void
    {
        $this->messenger->paragraphs(
            $user,
            [$this->messenger->line($user, $line)],
            $this->keyboard($user, ...$commands),
        );
    }

    /**
     * The dead end: the button they tapped is gone, and here is the way forward.
     *
     * One call rather than a line and a keyboard repeated at every site that can
     * reach a stale button — there are two dozen of them, and a dead end that
     * differs per entry point is how one of them ends up with nothing to tap.
     *
     * The default is `create` because that is what the line it replaces used to
     * say, and because it is the one command that works whatever state the user
     * is in: `/create` drops any open flow and starts a new one.
     */
    public function stale(User $user, string $line = 'bot.fallback.stale_button', string ...$commands): void
    {
        $this->send($user, $line, ...($commands === [] ? ['create'] : $commands));
    }

    /**
     * A row of command buttons, in the order named.
     *
     * Returned as a row rather than a keyboard so a caller with buttons of its
     * own can put them side by side — the channel gate's join link sits next to
     * its "come back in" button, and a second row under a message whose whole
     * point is one link would be a worse prompt.
     *
     * @return list<array{text: string, callback_data: string}>
     */
    public function row(User $user, string ...$commands): array
    {
        $row = [];

        foreach ($commands as $command) {
            $row[] = [
                'text' => $this->messenger->line($user, $this->menu->descriptionKey($command)),
                'callback_data' => BotCallback::encode(CommandCallback::ACTION, $command),
            ];
        }

        return $row;
    }

    /**
     * The row as a keyboard, or null when there is nothing to offer.
     *
     * Null rather than an empty array, because that is what both the messenger
     * seam and `BotMessenger::paragraphs()` read as "no keyboard" — an empty
     * array would be sent as `reply_markup: []`, which Telegram refuses.
     *
     * @return list<list<array{text: string, callback_data: string}>>|null
     */
    public function keyboard(User $user, string ...$commands): ?array
    {
        return $commands === [] ? null : [$this->row($user, ...$commands)];
    }

    /**
     * The check-in button for one challenge.
     *
     * Named for the challenge because a participant is in several: a bare "check
     * in" under a reminder about three challenges says nothing about which one
     * it means, and `CheckInCallback` resolves the challenge from the token it is
     * given rather than from anything the tap supplies.
     *
     * @return array{text: string, callback_data: string}
     */
    public function checkIn(User $user, Challenge $challenge): array
    {
        return [
            'text' => $this->messenger->line($user, 'bot.checkin.button', ['title' => $challenge->title]),
            'callback_data' => BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token),
        ];
    }

    /**
     * The button that buys one extra slot with coins.
     *
     * The price is read through `PurchaseEntitlement::priceOf()` rather than from
     * `Settings` directly, so the number shown on the button and the number
     * charged on the tap come from one reader and cannot drift apart. That is the
     * whole reason the price method is public.
     *
     * `$joinToken` is carried only for a **join** slot, and only when the refusal
     * happened while responding to a specific challenge's join — it is what lets
     * the purchase hand the user back to the join they were attempting instead of
     * a dead end. It is a routing hint, not an authorization: `JoinChallengeFlow`
     * re-resolves the challenge from the token and re-runs its own gate and slot
     * checks, so a forged or stale token buys nothing.
     *
     * @return array{text: string, callback_data: string}
     */
    public function buySlot(User $user, EntitlementType $type, ?string $joinToken = null): array
    {
        return [
            'text' => $this->messenger->line($user, 'bot.slots.buy_button', [
                'coins' => $this->purchases->priceOf($type),
            ]),
            'callback_data' => BotCallback::encode(
                SlotPurchaseCallback::ACTION,
                $type->value,
                ...($joinToken === null ? [] : [$joinToken]),
            ),
        ];
    }
}
