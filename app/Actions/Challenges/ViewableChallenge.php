<?php

namespace App\Actions\Challenges;

use App\Models\Challenge;
use App\Models\ChallengeParticipant;

/**
 * What `ResolveViewableChallenge` hands back: the challenge the token's user is
 * allowed to see, and their participation in it when there is one.
 *
 * A class rather than a tuple so call sites read `$view->challenge` and
 * `$view->participant` instead of unpacking positional array keys — the same
 * reason `StarsInvoice` is one.
 *
 * `$participant` is null for a creator who has not joined their own challenge,
 * which is a normal state rather than a missing one: creating a challenge does
 * not enrol its creator.
 */
final readonly class ViewableChallenge
{
    public function __construct(
        public Challenge $challenge,
        public ?ChallengeParticipant $participant,
    ) {}
}
