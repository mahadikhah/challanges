<?php

namespace App\Actions\Reminders;

use App\Enums\ReminderKind;
use App\Enums\SettingKey;
use App\Models\Challenge;
use App\Models\ChallengePeriod;
use App\Models\ReminderDispatch;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Materialise the reminder rows a challenge's near future needs.
 *
 * Reminders are two decisions, and this is the first: *what should exist*. It
 * writes `ReminderDispatch` rows — a nudge is a row, or it does not exist — and
 * never sends anything. `DispatchDueReminders` is the second decision, *what
 * goes out now*, and `SendReminder` is the send. Splitting them this way is what
 * makes the whole pipeline safe to re-run: this action's output is idempotent by
 * the unique `(participant, period, kind)` index, so however often it runs, a
 * participant gets each nudge for each period at most once.
 *
 * **Only the near future is materialised.** Rows are created for periods that
 * open within the horizon, not for a whole timeline at once — a yearly challenge
 * with a thousand participants would otherwise mint three quarters of a million
 * rows on day one. The trade is deliberate: a window that passed while the
 * scheduler was down never gets a row, so no stale "period open!" arrives three
 * days late for a period that has already closed.
 *
 * **`scheduled_for` is computed, never stored as a decision.** Every run derives
 * it the same way from the period's boundaries, so a re-run that finds an
 * existing row never needs to reconcile a different answer.
 */
class ScheduleChallengeReminders
{
    /**
     * How far ahead reminder rows are minted.
     *
     * Generous on purpose: an hour of scheduler downtime must not cost anybody
     * their "period ending" nudge, and the horizon only ever holds a period or
     * two of a daily challenge, so the cost of looking ahead is small.
     */
    private const HORIZON_HOURS = 24;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Mint the reminder rows for every period of this challenge that opens or
     * closes within the horizon.
     *
     * @return int how many rows this run created (existing ones are not counted)
     */
    public function handle(Challenge $challenge, ?CarbonInterface $now = null): int
    {
        $at = CarbonImmutable::instance($now ?? now());

        if ($challenge->status->isTerminal()) {
            return 0;
        }

        $created = 0;

        foreach ($this->horizonPeriods($challenge, $at) as $period) {
            foreach ($this->kindsFor($period, $at) as ['kind' => $kind, 'scheduled_for' => $scheduledFor]) {
                $created += $this->mint($challenge, $period, $kind, $scheduledFor);
            }
        }

        return $created;
    }

    /**
     * The challenge's periods whose reminders belong in the horizon: not yet
     * swept by the rollover, and opening before the horizon ends.
     *
     * @return Collection<int, ChallengePeriod>
     */
    private function horizonPeriods(Challenge $challenge, CarbonImmutable $at): Collection
    {
        return $challenge->periods()
            ->whereNull('rolled_over_at')
            ->where('starts_at', '<=', $at->addHours(self::HORIZON_HOURS))
            ->orderBy('index')
            ->get();
    }

    /**
     * Which nudges this period produces, and when each is due.
     *
     * The first period announces the challenge rather than itself — a
     * "challenge starting" and a "period 1 open" in the same breath would be one
     * message said twice.
     *
     * @return list<array{kind: ReminderKind, scheduled_for: CarbonImmutable}>
     */
    private function kindsFor(ChallengePeriod $period, CarbonImmutable $at): array
    {
        $kinds = [];

        $starting = $period->index === 0
            ? ReminderKind::ChallengeStarting
            : ReminderKind::PeriodOpened;

        // A window that has already passed never gets a row — the moment to be
        // reminded about is gone, and the rollover has or will settle the period.
        if ($period->starts_at->gte($at)) {
            $kinds[] = ['kind' => $starting, 'scheduled_for' => $period->starts_at];
        }

        $lead = $this->lead();

        if ($lead > 0) {
            // Clamped: a lead longer than the period would fire before the period
            // opens, which is a nudge about a question nobody can answer yet.
            $closingAt = $period->starts_at->max($period->ends_at->subHours($lead));

            if ($closingAt->gte($at)) {
                $kinds[] = ['kind' => ReminderKind::PeriodEnding, 'scheduled_for' => $closingAt];
            }
        }

        return $kinds;
    }

    /**
     * Mint one kind's rows for every participant who owes the period.
     *
     * One bulk `insertOrIgnore` rather than a `firstOrCreate` per participant:
     * the unique index is the arbiter either way, but the sweep runs every
     * minute and a thousand tiny round trips is exactly the load the shared
     * host does not need.
     *
     * Rows already sent are re-inserted-and-ignored by the index, so a
     * participant is never nudged twice for the same period and kind.
     */
    private function mint(Challenge $challenge, ChallengePeriod $period, ReminderKind $kind, CarbonImmutable $scheduledFor): int
    {
        $participantIds = $challenge->participants()
            ->active()
            ->where('joined_period_index', '<=', $period->index)
            ->pluck('id');

        if ($participantIds->isEmpty()) {
            return 0;
        }

        $rows = $participantIds
            ->map(fn (int $participantId): array => [
                'challenge_participant_id' => $participantId,
                'challenge_period_id' => $period->getKey(),
                'kind' => $kind->value,
                'scheduled_for' => $scheduledFor,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        return ReminderDispatch::query()->insertOrIgnore($rows);
    }

    /**
     * The admin-tuned lead on the closing nudge, floor-clamped at zero: a
     * negative lead is a misconfiguration, not an instruction to fire after the
     * period has closed.
     */
    private function lead(): int
    {
        return max(0, $this->settings->integer(SettingKey::ReminderEndingLeadHours));
    }
}
