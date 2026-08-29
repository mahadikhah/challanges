<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Http\Resources\MiniApp\ChallengeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The challenges the token's user takes part in.
 *
 * The list is participations, deliberately: creating a challenge does not
 * enrol its creator, so a challenge they run but never joined is not "theirs"
 * on this surface — the creator's review and moderation surface is the admin
 * panel's job, not the participant dashboard's.
 *
 * `show` resolves the participation from the token's user and the route's
 * challenge *together*, and 404s on any mismatch. A challenge that exists but
 * is somebody else's reads the same as one that does not exist at all — the
 * Mini App has no way to learn which ids are live.
 */
class ChallengeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $participations = $request->user()->participations()
            ->with(['challenge.periods', 'checkIns'])
            ->orderByDesc('id')
            ->get();

        return ChallengeResource::collection($participations);
    }

    public function show(Request $request, string $challenge): ChallengeResource
    {
        $participant = $request->user()->participations()
            ->where('challenge_id', $challenge)
            ->with(['challenge.periods', 'checkIns'])
            ->first();

        if ($participant === null) {
            abort(404);
        }

        return new ChallengeResource($participant);
    }
}
