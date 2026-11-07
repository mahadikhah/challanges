<?php

namespace App\Actions\Invites;

use App\Enums\InviteStatus;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Mint invite codes.
 *
 * **A code is single-use**, which is a property of the schema rather than a
 * choice made here: `invites.code` is unique and `invites.invited_user_id` is
 * unique, so one row can attribute exactly one arrival. `handle()` therefore
 * means "the inviter's current link" — it hands back the open code they already
 * have rather than littering the table with a new one on every `/invite`, so the
 * link a user copied yesterday is still the link they see today.
 *
 * Once somebody arrives through it, the next `handle()` mints a fresh one.
 */
class IssueInviteCode
{
    /**
     * Length of a generated code.
     *
     * Ten characters of the alphabet below is roughly 49 bits, which makes a
     * collision a non-event and a guess pointless. Codes ride in a Telegram
     * `?start=` payload, so they must stay well inside its 64-character limit.
     */
    private const CODE_LENGTH = 10;

    /**
     * Lowercase, digits, and none of `i`, `l`, `o`, `0` or `1`.
     *
     * A code gets read off one screen and typed into another, or dictated. Every
     * character that can be confused for a different character is a support
     * message, so the ambiguous ones are simply not in the pool.
     */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * How many times to re-roll on a collision before giving up.
     *
     * At 49 bits a single collision already means something is wrong with the
     * randomness, so this is a bounded safety net, not an expected path.
     */
    private const MINT_ATTEMPTS = 5;

    /**
     * The inviter's current link, minting one if they have none open.
     *
     * Two concurrent calls can both mint, leaving the inviter with two open
     * codes. Harmless — both are valid, both credit them — so this is not worth
     * a lock.
     */
    public function handle(User $inviter): Invite
    {
        return $inviter->sentInvites()->open()->oldest('id')->first()
            ?? $this->mint($inviter);
    }

    /**
     * A brand-new code, whether or not the inviter already has one open.
     *
     * For "give me another link" — an inviter who has posted their current code
     * somewhere public and wants a second one for a specific friend.
     *
     * @throws UniqueConstraintViolationException when the generator collides
     *                                            repeatedly, which means it is broken
     */
    public function mint(User $inviter): Invite
    {
        $attempts = 0;

        while (true) {
            try {
                return $inviter->sentInvites()->create([
                    'code' => $this->code(),
                    'status' => InviteStatus::Pending,
                ]);
            } catch (UniqueConstraintViolationException $collision) {
                // The unique index is the arbiter, not a pre-flight existence
                // check: two callers can both find a code free and then both
                // insert it. Re-rolling on the rejection is the only version of
                // this that is actually safe under concurrency.
                if (++$attempts >= self::MINT_ATTEMPTS) {
                    throw $collision;
                }
            }
        }
    }

    /**
     * One random code. `random_int` rather than `rand`, because a guessable code
     * lets a stranger take credit for an arrival.
     */
    private function code(): string
    {
        $alphabet = str_split(self::ALPHABET);
        $last = count($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $last)];
        }

        return $code;
    }
}
