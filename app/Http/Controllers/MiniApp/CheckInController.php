<?php

namespace App\Http\Controllers\MiniApp;

use App\Actions\CheckIns\SubmitCheckIn;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\ProofType;
use App\Exceptions\CheckInRejectedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MiniApp\ChallengeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Mini App's non-media check-in.
 *
 * `button` challenges are proven by a tap and `text_autogen` challenges by
 * typing the phrase the resource issued for this period — the two non-media
 * proofs, each auto-approved. The endpoint dispatches on the challenge's own
 * `proof_type` to the same `SubmitCheckIn` entry points the bot's callbacks
 * call, so the rule cannot be right in one surface and wrong in the other:
 * `tap()` and `typePhrase()` through the bot and here are one action.
 *
 * A media proof (`image_approval`, `voice_approval`, `video_approval`) has no
 * path here — its proof exists in the bot, and uploading it is that surface's
 * job. This endpoint's `wrong_proof_type` refusal is what a client that tries
 * anyway runs into, and the SPA sends those participants to the bot instead of
 * trying.
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

        // The phrase a text challenge asks to be typed back, and the quantity
        // report a scoring challenge asks for alongside either proof. Both
        // nullable — and `text` is deliberately not `required`, because a
        // missing phrase must reach `typePhrase()` and be refused with its own
        // `proof_missing` reason rather than a 422 validation bag the SPA
        // cannot render. `reported_value` follows the same rule for a quantity
        // submission: `value_required` is the reason the SPA shows its input.
        $data = $request->validate([
            'text' => ['nullable', 'string'],
            'reported_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            // `tap()` is the default branch on purpose: `openSubmittable()`
            // owns the `wrong_proof_type` refusal for a media proof, so a
            // handler here would be a second copy of a rule that task 5's
            // scope keeps unchanged.
            match ($participant->challenge->proof_type) {
                ProofType::TextAutogen => $this->submit->typePhrase(
                    $request->user(),
                    $participant->challenge,
                    $data['text'] ?? '',
                    reportedValue: $data['reported_value'] ?? null,
                ),
                default => $this->submit->tap(
                    $request->user(),
                    $participant->challenge,
                    reportedValue: $data['reported_value'] ?? null,
                ),
            };
        } catch (CheckInRejectedException $refused) {
            // The reason, machine-readable; the sentence lives in the SPA's
            // own catalogue in the user's locale. A bare reason keeps this
            // endpoint locale-independent and the copy single-sourced client-side.
            return response()->json(['reason' => $refused->reason->value], 422);
        }

        // The settlement may have changed the participant (streak, the new
        // check-in row), so everything the resource reads is reloaded — the
        // response is the SPA's new state, not a patch to merge. `detail()` so
        // a settled phrase row carries the same shape it did while owed (minus
        // the phrase itself, which the resource drops once settled).
        $participant->refresh();

        return ChallengeResource::detail($participant->challenge, $participant, $request->user())
            ->response()
            ->setStatusCode(201);
    }
}
