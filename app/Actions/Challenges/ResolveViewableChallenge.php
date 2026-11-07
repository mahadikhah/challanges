<?php

namespace App\Actions\Challenges;

use App\Models\ChallengeParticipant;
use App\Models\User;

/**
 * The one rule for "is this challenge the token's user's to look at".
 *
 * Two ways in, and only two: they are in it (a participation row, whatever its
 * status), or they run it. `CreateChallenge` deliberately writes no participant
 * row for its creator, so the two sets genuinely differ and a listing or a
 * detail screen built from either one alone loses the other half.
 *
 * The second lookup is scoped by `$user->createdChallenges()` so the ownership
 * test is the model's own `creator_id` comparison rather than a column name
 * written by hand — and so the route id stays a lookup key, never an
 * authorization.
 *
 * **A miss is a 404, never a 403.** A challenge that exists but is somebody
 * else's has to read exactly like one that was never created; a distinguishable
 * refusal would turn the route into an id oracle, and the Mini App would leak
 * which ids are live. The `abort` lives here rather than in a caller because
 * that uniformity *is* the rule, not an implementation detail of one controller
 * — the detail screen and the join endpoint share it verbatim.
 *
 * The eager loads are the resources' contract, not an optimization: every
 * caller renders a `ChallengeResource`, which reads the timeline off the
 * challenge and the viewer's own check-ins off the participation. Kept here so
 * a third caller cannot forget them and pay a silent N+1 —
 * `Model::preventLazyLoading()` is not enabled anywhere in this app.
 */
class ResolveViewableChallenge
{
    public function handle(User $user, string $challenge): ViewableChallenge
    {
        $participant = $user->participations()
            ->where('challenge_id', $challenge)
            ->with(['challenge.periods', 'checkIns'])
            ->first();

        if ($participant instanceof ChallengeParticipant) {
            return new ViewableChallenge($participant->challenge, $participant);
        }

        $owned = $user->createdChallenges()
            ->where('id', $challenge)
            ->with('periods')
            ->first();

        if ($owned === null) {
            abort(404);
        }

        return new ViewableChallenge($owned, null);
    }
}
