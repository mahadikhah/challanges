<?php

namespace App\Http\Controllers\MiniApp;

use App\Actions\CheckIns\SubmitCheckIn;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Exceptions\CheckInRejectedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MiniApp\ChallengeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Mini App's one-tap check-in.
 *
 * `button` challenges are proven by exactly this: a tap, auto-approved. The
 * endpoint calls the same `SubmitCheckIn` action the bot's callback does, so
 * the rule cannot be right in one surface and wrong in the other. Phrase and
 * photo challenges keep their proof path in the bot for now — recorded as a
 * follow-up in progress.md — and this endpoint's `wrong_proof_type` refusal
 * is what a client that tries anyway runs into.
 *
 * The channel gate is re-verified here, not at auth: CLAUDE.md pins
 * "re-verify on privileged actions", and submitting proof is the privileged
 * action. A user who left the announcement channel since their last check-in
 * is refused with their join link, not silently served.
 */
class CheckInController extends Controller
{
    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly SubmitCheckIn $submit,
    ) {}

    public function store(Request $request, string $challenge): JsonResponse
    {
        $participant = $request->user()->participations()
            ->where('challenge_id', $challenge)
            ->with(['challenge.periods', 'checkIns'])
            ->first();

        // Same uniformity as `show`: a challenge that is not the token user's
        // to check in against reads as one that does not exist.
        if ($participant === null) {
            abort(404);
        }

        if (! $this->gate->ensure($request->user())) {
            return response()->json([
                'reason' => 'channel_gate',
                // Null for a numeric channel id: there is genuinely no public
                // URL to join with, and the client says so rather than
                // offering a broken button.
                'join_url' => $this->gate->joinUrl($request->user()),
            ], 403);
        }

        try {
            $this->submit->tap($request->user(), $participant->challenge);
        } catch (CheckInRejectedException $refused) {
            // The reason, machine-readable; the sentence lives in the SPA's
            // own catalogue in the user's locale. A bare reason keeps this
            // endpoint locale-independent and the copy single-sourced client-side.
            return response()->json(['reason' => $refused->reason->value], 422);
        }

        // The settlement may have changed the participant (streak, the new
        // check-in row), so everything the resource reads is reloaded — the
        // response is the SPA's new state, not a patch to merge.
        $participant->refresh();

        return (new ChallengeResource($participant))
            ->response()
            ->setStatusCode(201);
    }
}
