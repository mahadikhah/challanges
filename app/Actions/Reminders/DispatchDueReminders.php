<?php

namespace App\Actions\Reminders;

use App\Jobs\Telegram\SendReminder;
use App\Models\ReminderDispatch;

/**
 * Hand the due reminder rows to the queue, one staggered job each.
 *
 * The second half of the reminder pipeline: `ScheduleChallengeReminders` decided
 * what exists, this decides what leaves. It never talks to Telegram itself — a
 * direct send from a cron sweep would put a minute-long burst of `sendMessage`
 * calls inside the web request's worker, exactly the blast CLAUDE.md's
 * rate-limit rule exists to prevent.
 *
 * **The stagger is the rate limit.** One job per row, each delayed one second
 * past the last, so the batch trickles out at one message a second — under
 * Telegram's per-chat limit by construction, because one row is one chat, and
 * far enough under the ~30/sec global limit to leave room for everything else
 * the bot does. There is no Redis token bucket to enforce this; the queue's own
 * clock is the only throttle, which is all the production host offers.
 *
 * **The batch fits inside one cron tick.** `BATCH * 1s` of delay must drain
 * before the next minute's run, or the same row would be re-dispatched while its
 * first job still sits in the queue. Thirty rows and thirty seconds leaves half
 * the minute as slack for a slow worker; the arithmetic is asserted in tests so
 * a "quick" bump to a hundred cannot slip in unnoticed.
 */
class DispatchDueReminders
{
    /**
     * How many due rows one run may dispatch.
     */
    public const BATCH = 30;

    /**
     * Seconds between consecutive sends — the trickle rate.
     */
    public const STAGGER_SECONDS = 1;

    /**
     * Queue the oldest due-and-unsent reminders, staggered.
     *
     * @return int how many jobs were dispatched
     */
    public function handle(): int
    {
        $dispatched = 0;

        ReminderDispatch::query()
            ->due()
            ->limit(self::BATCH)
            ->get()
            ->each(function (ReminderDispatch $reminder, int $position) use (&$dispatched): void {
                // The row is addressed by id, not carried: the job re-reads it
                // under a lock when it runs, so a row settled or suppressed in
                // the meantime is honoured rather than acted on stale.
                dispatch(
                    (new SendReminder($reminder->getKey()))
                        ->delay($position * self::STAGGER_SECONDS)
                );

                $dispatched++;
            });

        return $dispatched;
    }
}
