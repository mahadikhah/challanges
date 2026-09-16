<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Http\Resources\MiniApp\ChallengeResource;
use App\Models\ChallengeParticipant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The challenges the token's user is in, and the ones they run.
 *
 * **Both halves, deliberately.** Creating a challenge does not enrol its
 * creator, so a challenge they run but never joined has no participation row
 * and would be invisible on a listing built from participations alone — the
 * creator could not open the thing they had just made. The union and its order
 * are `User::challengeIdsNewestFirst()`, the same rule the bot's `/challenges`
 * listing uses; this controller only decides what each row carries.
 *
 * `show` resolves the token's user and the route's challenge *together* — as a
 * participation or as an owner — and 404s on neither. A challenge that exists
 * but is somebody else's reads the same as one that does not exist at all: the
 * Mini App has no way to learn which ids are live. That uniformity is the whole
 * point of the second lookup, so the ownership query stays scoped by
 * `$user->createdChallenges()` and the route id stays a lookup key, never an
 * authorization.
 */
class ChallengeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $participations = $user->participations()
            ->with(['challenge.periods', 'checkIns'])
            ->get()
            ->keyBy('challenge_id');

        // The timeline comes with the challenge rather than through it: an owned
        // challenge has no participation row for `challenge.periods` to hang off,
        // and letting it lazy-load would be one query per challenge owned.
        $created = $user->createdChallenges()
            ->with('periods')
            ->get()
            ->keyBy('id');

        $rows = $user->challengeIdsNewestFirst($participations->keys(), $created->keys())
            ->map(function (int $id) use ($participations, $created, $user): ChallengeResource {
                /** @var ChallengeParticipant|null $participation */
                $participation = $participations->get($id);

                return new ChallengeResource(
                    // The created copy wins when a challenge is in both sets —
                    // the same tie-break `MyChallengesFlow::rows()` makes, for
                    // the same reason: it is the copy that carries what the row
                    // needs, here the eager-loaded timeline.
                    $created->get($id) ?? $participation->challenge,
                    $participation,
                    $user,
                );
            });

        return ChallengeResource::collection($rows);
    }

    public function show(Request $request, string $challenge): ChallengeResource
    {
        /** @var User $user */
        $user = $request->user();

        $participant = $user->participations()
            ->where('challenge_id', $challenge)
            ->with(['challenge.periods', 'checkIns'])
            ->first();

        if ($participant !== null) {
            return new ChallengeResource($participant->challenge, $participant, $user);
        }

        // Owning it is the other, and only other, way to be entitled to look.
        // Read through the relation so the ownership test is the model's own
        // `creator_id` comparison and not a column name written by hand here.
        $owned = $user->createdChallenges()
            ->where('id', $challenge)
            ->with('periods')
            ->first();

        if ($owned === null) {
            abort(404);
        }

        return new ChallengeResource($owned, null, $user);
    }
}
