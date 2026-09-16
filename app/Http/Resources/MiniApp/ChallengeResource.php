<?php

namespace App\Http\Resources\MiniApp;

use App\Enums\Contracts\HasTranslatedLabel;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One challenge as the Mini App sees it: the shared timeline, and — when the
 * viewer has one — their own place in it.
 *
 * **Two facts, not one.** The resource is built from the challenge *and* the
 * viewer's optional participation, because `CreateChallenge` does not enrol its
 * creator: a challenge the user runs but never joined has no participation row
 * at all, and the creator still has to see it. `me` is therefore nullable —
 * a creator is a viewer of their own challenge before they are a participant in
 * it — and `has_joined` states which of the two shapes this is, so `me: null`
 * can never be misread as a participant whose counters happen to be zero.
 *
 * **The constructor takes three arguments, deliberately, against the
 * one-argument convention `MiniAppUserResource` documents.** That convention
 * exists so `::make()` and `::collection()` can build each member from a single
 * value; here a member genuinely needs two values plus the viewer, so callers
 * build each resource explicitly and hand `::collection()` a collection of
 * already-built resources. Laravel supports that path on purpose —
 * `ResourceCollection::toArray()` branches on whether the first item is already
 * a `JsonResource` — so the only thing lost is `::make()`, which no caller wants.
 *
 * Labels resolve in the ambient locale `SetLocale` picked: the token's
 * user's stored locale first, then the `Accept-Language` the SPA sent,
 * then the configured fallback.
 */
class ChallengeResource extends JsonResource
{
    public function __construct(
        Challenge $challenge,
        private readonly ?ChallengeParticipant $participant,
        private readonly User $viewer,
    ) {
        parent::__construct($challenge);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = CarbonImmutable::now();

        /** @var Challenge $challenge */
        $challenge = $this->resource;

        $participant = $this->participant;
        $periods = $challenge->periods;

        $current = $periods->first(fn (ChallengePeriod $period): bool => $period->contains($now));

        /** @var Collection<int, ChallengePeriod> $settled */
        $settled = $periods->filter(fn (ChallengePeriod $period): bool => $period->hasEnded($now));

        return [
            'id' => $challenge->getKey(),
            'title' => $challenge->title,
            'description' => $challenge->description,
            'status' => $this->enum($challenge->status),
            'period_type' => $this->enum($challenge->period_type),
            'starts_at' => $challenge->starts_at->toIso8601String(),
            'timezone' => $challenge->timezone,
            'total_periods' => $challenge->total_periods,
            'proof_type' => $this->enum($challenge->proof_type),
            'visibility' => $this->enum($challenge->visibility),

            // Read off the viewer, never off the participant. With no
            // participation there is no `user_id` to compare, and the two are
            // not the same question anyway: the controller scopes every lookup
            // to the token's user, so this is the same answer it always was for
            // somebody who joined, and the only answer available for somebody
            // who did not.
            'is_creator' => $challenge->creator_id === $this->viewer->getKey(),

            // Whether a participation row exists — not whether it is `Active`.
            // A participant who completed, left or was removed still has one,
            // and `me.status` is what names which of those they are.
            'has_joined' => $participant !== null,

            // The quantity scoring design, or null on a binary challenge —
            // the SPA shows its numeric input only when this is present, and
            // the fields are exactly what the confirmation quotes back.
            'scoring' => $challenge->scoring_type->isQuantity() ? [
                'target_value' => (float) $challenge->target_value,
                'unit_label' => (string) $challenge->unit_label,
                'base_points' => (int) $challenge->base_points,
                'partial_counts_as_done' => (bool) $challenge->quantity_partial_counts_as_done,
            ] : null,

            // Null — never zeroes — without a participation. A zeroed grid
            // would tell somebody who is not in the challenge that they are
            // keeping a 0-day streak, which is a different and false claim.
            'me' => $participant === null ? null : $this->meShape($participant),

            // The period open right now — null before the timeline starts,
            // between periods (which cannot happen on a materialised timeline),
            // and after the final one closes. Present for every viewer, because
            // it describes the *challenge's* timeline: a creator who never
            // joined still needs "period 3 of 30" and when it ends. Only the
            // two fields that belong to a participant go neutral without one.
            'current_period' => $current === null
                ? null
                : $this->currentPeriodShape($challenge, $participant, $current),

            // The participant's own record, one entry per period that has
            // closed since they joined. A late joiner's history starts where
            // their obligations did — the shared timeline is not theirs to be
            // judged on before that. Empty without a participation: this is a
            // record, and there is nobody here to have one.
            'history' => $participant === null
                ? []
                : $this->historyShape($challenge, $participant, $settled),
        ];
    }

    /**
     * How the viewer stands in this challenge.
     *
     * @return array<string, mixed>
     */
    private function meShape(ChallengeParticipant $participant): array
    {
        return [
            'status' => $this->enum($participant->status),
            'current_streak' => $participant->current_streak,
            'longest_streak' => $participant->longest_streak,
            'total_score' => (float) $participant->total_score,
            'joined_period_index' => $participant->joined_period_index,
            'freezes' => [
                'total' => $participant->freezes_total,
                'used' => $participant->freezes_used,
                'remaining' => $participant->freezesRemaining(),
            ],
        ];
    }

    /**
     * The timeline's open period, with whatever the viewer owes on it.
     *
     * `owes_check_in` is false and `check_in` is null when there is no
     * participation, and that is the honest answer rather than a placeholder:
     * somebody who is not in the challenge owes nothing, and has nothing
     * recorded. It also keeps the SPA's check-in affordance off for a creator
     * who has not joined yet, on the read path as well as the write path.
     *
     * @return array<string, mixed>
     */
    private function currentPeriodShape(
        Challenge $challenge,
        ?ChallengeParticipant $participant,
        ChallengePeriod $current,
    ): array {
        return [
            'index' => $current->index,
            'starts_at' => $current->starts_at->toIso8601String(),
            'ends_at' => $current->ends_at->toIso8601String(),
            'owes_check_in' => $participant !== null
                && $participant->owesPeriod($current)
                && ($this->checkInFor($participant, $current)?->status->allowsSubmission() ?? true),
            'check_in' => $participant === null
                ? null
                : $this->checkInShape($challenge, $participant, $current),
        ];
    }

    /**
     * One entry per settled period since the participant joined.
     *
     * @param  Collection<int, ChallengePeriod>  $settled
     * @return array<int, array{index: int, status: array<string, mixed>}>
     */
    private function historyShape(
        Challenge $challenge,
        ChallengeParticipant $participant,
        Collection $settled,
    ): array {
        return $settled
            ->filter(fn (ChallengePeriod $period): bool => $period->index >= $participant->joined_period_index)
            ->values()
            ->map(fn (ChallengePeriod $period): array => [
                'index' => $period->index,
                'status' => $this->checkInShape($challenge, $participant, $period) ?? [
                    // The period closed but rollover has not swept it yet,
                    // so no terminal row exists. Rare and momentary — the
                    // sweep runs every minute — but the SPA's grid still
                    // needs an entry for the slot.
                    'value' => 'pending',
                    'label' => __('enums.check_in_status.pending'),
                ],
            ])->all();
    }

    /**
     * The check-in row for one period, if there is one.
     */
    private function checkInFor(ChallengeParticipant $participant, ChallengePeriod $period): ?CheckIn
    {
        return $participant->checkIns->first(
            fn (CheckIn $checkIn): bool => $checkIn->challenge_period_id === $period->getKey(),
        );
    }

    /**
     * A check-in as the Mini App states it: its status and the label for it.
     *
     * On a quantity challenge, the reported number and the score it earned ride
     * along — the settled row's own record, so the SPA's history grid can show
     * "45 · 150 pts" without recomputing anything.
     *
     * @return array{value: int|string, label: string, reported_value?: float|null, score?: int|null}|null
     */
    private function checkInShape(
        Challenge $challenge,
        ChallengeParticipant $participant,
        ChallengePeriod $period,
    ): ?array {
        $checkIn = $this->checkInFor($participant, $period);

        if ($checkIn === null) {
            return null;
        }

        $shape = $this->enum($checkIn->status);

        if ($challenge->scoring_type->isQuantity()) {
            // The model's decimal cast hands back strings; the wire carries
            // numbers, so the two are normalised here at the boundary.
            $shape['reported_value'] = $checkIn->reported_value !== null ? (float) $checkIn->reported_value : null;
            $shape['score'] = $checkIn->score !== null ? (int) $checkIn->score : null;
        }

        return $shape;
    }

    /**
     * Every enum the Mini App shows, as value + translated label, so the SPA
     * never has to know a label or hardcode one. `value`'s type is what
     * `BackedEnum` allows — int|string — though every enum that reaches here
     * is string-backed, so the wire always carries a string.
     *
     * @return array{value: int|string, label: string}
     */
    private function enum(\BackedEnum&HasTranslatedLabel $case): array
    {
        return [
            'value' => $case->value,
            'label' => $case->label(),
        ];
    }
}
