<?php

namespace App\Services\Telegram;

use App\Enums\InviteRejection;
use App\Models\Challenge;
use App\Models\Invite;
use App\Models\User;

/**
 * What a `/start` arrived with — and, when the language question interrupts it,
 * what that `/start` still owes.
 *
 * `/start` does three things a second `/start` cannot do again: it claims the
 * invite code they arrived with (which pays out only if *this* update created
 * them), it remembers whether this was a first arrival, and it holds the join
 * payload they followed. Answering the language question means the greeting is
 * deferred to a later update, and by then none of those three are recoverable:
 * a second `ClaimInvite` would find nobody new to pay and could only report
 * "already claimed", and `wasRecentlyCreated` belongs to the instance that did
 * the INSERT.
 *
 * So the arrival is **carried, not recomputed**. Its three facts ride on the
 * language buttons themselves — the callback data is the only thing that
 * survives from the message that was sent to the tap that answers it — and this
 * class is the one place that knows how to write them down and read them back.
 *
 * Reading back is deliberately timid. The payload is a string a client sent us,
 * so every part of it is re-checked against our own rows before it is trusted:
 * an invite is re-read (never re-claimed) and only if it is *this* user's, a
 * refusal reason is looked up in the enum rather than interpolated, and a join
 * payload has to still look like one.
 */
final readonly class StartArrival
{
    /**
     * The word that marks a language button as `/start`'s rather than `/language`'s.
     *
     * `BotCallback::argument()` returns null for an absent segment, so an
     * ordinary `/language` button — which carries nothing after the locale — is
     * told apart by this marker rather than by a count.
     */
    public const string FROM_START = 'start';

    private const string FIRST = 'first';

    private const string BACK = 'back';

    private const string KIND_INVITE = 'invite';

    private const string KIND_REFUSED = 'refused';

    private const string KIND_JOIN = 'join';

    /**
     * @param  Invite|InviteRejection|null  $invite  the claimed invite, the reason it
     *                                               was refused, or null if none was offered
     * @param  string|null  $joinPayload  the raw `?start=` payload they followed, which may
     *                                    name a challenge that no longer exists
     */
    public function __construct(
        public bool $isFirstArrival,
        public Invite|InviteRejection|null $invite,
        public ?string $joinPayload,
    ) {}

    /**
     * What every language button on this prompt should carry.
     *
     * A join payload wins over an invite outcome because `/start` treats them as
     * mutually exclusive: a payload that names a challenge is never handed to
     * `ClaimInvite`. The join payload travels verbatim rather than as a
     * challenge id, so the greeting re-runs the same resolution `/start` ran and
     * a challenge deleted in the meantime is reported as the dead link it now
     * is.
     *
     * @return list<string>
     */
    public function carry(): array
    {
        $carry = [self::FROM_START, $this->isFirstArrival ? self::FIRST : self::BACK];

        if ($this->joinPayload !== null) {
            return [...$carry, self::KIND_JOIN, $this->joinPayload];
        }

        return match (true) {
            $this->invite instanceof Invite => [...$carry, self::KIND_INVITE, (string) $this->invite->getKey()],
            $this->invite instanceof InviteRejection => [...$carry, self::KIND_REFUSED, $this->invite->value],
            default => $carry,
        };
    }

    /**
     * What a tapped language button says `/start` owed, or null when the tap was
     * an ordinary `/language` one with nothing outstanding.
     */
    public static function carried(User $user, BotCallback $callback): ?self
    {
        if ($callback->argument(1) !== self::FROM_START) {
            return null;
        }

        $kind = $callback->argument(3);
        $value = $callback->argument(4);

        return new self(
            $callback->argument(2) === self::FIRST,
            self::inviteOf($user, $kind, $value),
            $kind === self::KIND_JOIN ? self::joinPayload($value) : null,
        );
    }

    /**
     * The invite the prompt was showing — read, never claimed.
     *
     * The row is re-read rather than rebuilt because the note needs two things
     * only the row knows: who invited them, and whether the inviter was actually
     * paid. Re-reading credits nobody; the coins moved once, when `ClaimInvite`
     * ran inside the `/start` that created this arrival.
     */
    private static function inviteOf(User $user, ?string $kind, ?string $value): Invite|InviteRejection|null
    {
        if ($kind === self::KIND_REFUSED) {
            // A reason from our own vocabulary or none at all: the value is
            // interpolated into a translation key, so an arbitrary string would
            // be a way to make the bot say something it has no line for.
            return $value === null ? null : InviteRejection::tryFrom($value);
        }

        if ($kind !== self::KIND_INVITE || $value === null) {
            return null;
        }

        /** @var Invite|null $invite */
        $invite = Invite::query()->find((int) $value);

        // Only ever their own. The note says "X invited you" — a forged payload
        // must not make the bot say that on somebody else's behalf.
        return $invite?->invited_user_id === $user->getKey() ? $invite : null;
    }

    private static function joinPayload(?string $value): ?string
    {
        return $value !== null && Challenge::isJoinPayload($value) ? $value : null;
    }
}
