<?php

namespace App\Console\Commands\Challenges;

use App\Actions\Reminders\DispatchDueReminders;
use App\Actions\Reminders\ScheduleChallengeReminders;
use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use Illuminate\Console\Command;

/**
 * The reminder pipeline's minute: materialise what is coming, dispatch what is
 * due.
 *
 * Both halves in one command on purpose. The cron on the shared host is one
 * line, the queue is one `queue:work --stop-when-empty --max-time=55` also on
 * cron, and neither half is useful without the other running at the same
 * cadence — a scheduler that cannot send and a sender that has nothing queued
 * are both silent.
 *
 * The command itself owns no timing rules. When a nudge goes out is
 * `scheduled_for` on the row (cut from the period boundaries), how fast they
 * trickle is `DispatchDueReminders`' stagger, and what a nudge says is
 * `SendReminder`'s copy. This is just the crank that turns both wheels every
 * minute.
 */
class SendRemindersCommand extends Command
{
    protected $signature = 'challenges:reminders';

    protected $description = 'Mint upcoming reminder rows and queue the due ones, staggered';

    public function handle(
        ScheduleChallengeReminders $schedule,
        DispatchDueReminders $dispatch,
    ): int {
        $minted = 0;

        // Only challenges with a period opening inside the horizon are even
        // considered — the action re-checks each period, this just keeps the
        // challenge list from dragging in every long-dead timeline.
        Challenge::query()
            ->whereIn('status', [ChallengeStatus::Scheduled, ChallengeStatus::Active])
            ->whereHas('periods', fn ($query) => $query
                ->whereNull('rolled_over_at')
                ->where('starts_at', '<=', now()->addDay()))
            ->eachById(function (Challenge $challenge) use ($schedule, &$minted): void {
                $minted += $schedule->handle($challenge);
            });

        $dispatched = $dispatch->handle();

        $this->components->twoColumnDetail('Reminder rows minted', (string) $minted);
        $this->components->twoColumnDetail('Reminders queued', (string) $dispatched);

        return self::SUCCESS;
    }
}
