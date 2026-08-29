<?php

namespace App\Http\Resources\MiniApp;

use App\Enums\Contracts\HasTranslatedLabel;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One challenge as the Mini App sees it: the shared timeline, and one
 * participant's place in it.
 *
 * The resource wraps the `ChallengeParticipant` — the Mini App is the user's
 * own view, so "the challenge" and "how I am doing in it" are one shape, not
 * two endpoints the SPA has to stitch.
 *
 * Labels resolve in the ambient locale `SetLocale` picked: the token's
 * user's stored preference first, then the `Accept-Language` the SPA sent,
 * then the configured fallback.
 *
 * @mixin ChallengeParticipant
 */
class ChallengeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = CarbonImmutable::now();
        $challenge = $this->challenge;
        $periods = $challenge->periods;

        /** @var Collection<int, ChallengePeriod> $settled */
        $settled = $periods->filter(fn ($period): bool => $period->hasEnded($now));
        $current = $periods->first(fn ($period): bool => $period->contains($now));

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
            'is_creator' => $challenge->creator_id === $this->user_id,

            // The quantity scoring design, or null on a binary challenge —
            // the SPA shows its numeric input only when this is present, and
            // the fields are exactly what the confirmation quotes back.
            'scoring' => $challenge->scoring_type->isQuantity() ? [
                'target_value' => (float) $challenge->target_value,
                'unit_label' => (string) $challenge->unit_label,
                'base_points' => (int) $challenge->base_points,
                'partial_counts_as_done' => (bool) $challenge->quantity_partial_counts_as_done,
            ] : null,

            'me' => [
                'status' => $this->enum($this->status),
                'current_streak' => $this->current_streak,
                'longest_streak' => $this->longest_streak,
                'total_score' => (float) $this->total_score,
                'joined_period_index' => $this->joined_period_index,
                'freezes' => [
                    'total' => $this->freezes_total,
                    'used' => $this->freezes_used,
                    'remaining' => $this->freezesRemaining(),
                ],
            ],

            // The period open right now — null before the timeline starts,
            // between periods (which cannot happen on a materialised
            // timeline), and after the final one closes.
            'current_period' => $current === null ? null : [
                'index' => $current->index,
                'starts_at' => $current->starts_at->toIso8601String(),
                'ends_at' => $current->ends_at->toIso8601String(),
                'owes_check_in' => $this->owesPeriod($current) && (
                    $this->checkInFor($current)?->status->allowsSubmission() ?? true
                ),
                'check_in' => $this->checkInShape($current),
            ],

            // The participant's own record, one entry per period that has
            // closed since they joined. A late joiner's history starts where
            // their obligations did — the shared timeline is not theirs to be
            // judged on before that.
            'history' => $settled
                ->filter(fn ($period): bool => $period->index >= $this->joined_period_index)
                ->values()
                ->map(fn ($period): array => [
                    'index' => $period->index,
                    'status' => $this->checkInShape($period) ?? [
                        // The period closed but rollover has not swept it yet,
                        // so no terminal row exists. Rare and momentary — the
                        // sweep runs every minute — but the SPA's grid still
                        // needs an entry for the slot.
                        'value' => 'pending',
                        'label' => __('enums.check_in_status.pending'),
                    ],
                ])->all(),
        ];
    }

    /**
     * The check-in row for one period, if there is one.
     */
    private function checkInFor(ChallengePeriod $period): ?CheckIn
    {
        return $this->checkIns->first(
            fn ($checkIn): bool => $checkIn->challenge_period_id === $period->getKey(),
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
    private function checkInShape(ChallengePeriod $period): ?array
    {
        $checkIn = $this->checkInFor($period);

        if ($checkIn === null) {
            return null;
        }

        $shape = $this->enum($checkIn->status);

        if ($this->challenge->scoring_type->isQuantity()) {
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
