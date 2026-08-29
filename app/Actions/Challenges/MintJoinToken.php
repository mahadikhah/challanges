<?php

namespace App\Actions\Challenges;

use App\Models\Challenge;
use RuntimeException;

/**
 * Mint the unguessable token that a challenge's join link is keyed on.
 *
 * **Why a token and not the id.** An invite-only challenge whose link read
 * `?start=j_417` would be unlisted rather than invite-only: anybody could walk the
 * integers and enrol in every private challenge on the platform. The token is what
 * makes "you cannot join without a link from me" true.
 *
 * Separate from `IssueInviteCode` despite the shared alphabet, because the two have
 * different lifetimes and different jobs: an invite code is single-use and is
 * reminted once claimed, while a join token is minted once with the challenge and
 * lives as long as it does. Sharing a class would mean one set of rules bent to fit
 * two behaviours.
 */
class MintJoinToken
{
    /**
     * Twelve characters of the alphabet below is a little under 60 bits — far past
     * anything worth guessing, and short enough to leave room in Telegram's
     * 64-character `?start=` payload once the `j_` prefix is on the front.
     */
    private const TOKEN_LENGTH = 12;

    /**
     * The same unambiguous set `IssueInviteCode` uses: lowercase and digits, minus
     * `i`, `l`, `o`, `0` and `1`. A join link gets read off one screen and typed
     * into another often enough to matter.
     *
     * Note it contains no `_`, which is what lets the bot tell a join token from an
     * invite code by the `j_` prefix alone with no risk of a code being mistaken
     * for one.
     */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * How many times to re-roll before giving up. At ~60 bits one collision means
     * the randomness is broken, so this is a bounded safety net rather than a path
     * anybody travels.
     */
    private const MINT_ATTEMPTS = 5;

    /**
     * A token no existing challenge is using.
     *
     * The pre-flight check cannot be authoritative — two callers can both find a
     * token free and both insert it — so `challenges.join_token` carries a unique
     * index as the actual arbiter. A caller that loses that race gets a constraint
     * violation and a rolled-back create, which at these odds is a better trade
     * than the complexity of retrying an entire creation transaction.
     *
     * @throws RuntimeException when the generator keeps colliding, which means it
     *                          is not generating what it claims to
     */
    public function handle(): string
    {
        for ($attempt = 0; $attempt < self::MINT_ATTEMPTS; $attempt++) {
            $token = $this->token();

            if (! Challenge::query()->where('join_token', $token)->exists()) {
                return $token;
            }
        }

        throw new RuntimeException(
            'Could not mint a unique challenge join token in '.self::MINT_ATTEMPTS.' attempts.',
        );
    }

    /**
     * One random token. `random_int` rather than `rand`, because a predictable
     * token is a private challenge anybody can walk into.
     */
    private function token(): string
    {
        $alphabet = str_split(self::ALPHABET);
        $last = count($alphabet) - 1;
        $token = '';

        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $token .= $alphabet[random_int(0, $last)];
        }

        return $token;
    }
}
