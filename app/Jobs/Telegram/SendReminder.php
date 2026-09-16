<?php

namespace App\Jobs\Telegram;

use App\Enums\ReminderKind;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\ReminderDispatch;
use App\Services\Telegram\BotButtons;
use App\Services\Telegram\BotMessenger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Send one reminder, exactly once, and say so on the row.
 *
 * The send half of the reminder pipeline, and the only part that talks to
 * Telegram. It is deliberately re-runnable from anywhere: the dispatch sweep,
 * a retry after a Telegram outage, an operator replaying a stalled row. Every
 * entry re-reads the row under its own lock and re-checks the world, so however
 * many copies of this job exist for one row, the message goes out once and the
 * rest return without saying anything.
 *
 * **`sent_at` is the idempotency token, stamped after the send.** If the send
 * throws, the row stays unsent and the job retries — a participant would rather
 * get a nudge a minute late than never, and never is what a stamp-before-send
 * would buy. Suppressed reminders (the participant already checked in, the
 * period already swept) are also stamped: the row means "this nudge has been
 * dealt with", which includes "decidedly not sending it".
 *
 * **The message is composed at send time, in the recipient's locale, against the
 * period as it stands.** A reminder row carries only a when; everything it says
 * — challenge, period number, closing time in the challenge's timezone — is
 * resolved fresh, so a renamed challenge never gets quoted by its old title.
 */
class SendReminder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How many times a flaky Telegram send may be retried before the row is
     * left for the next sweep to pick up as a stalled one.
     */
    public int $tries = 5;

    public function __construct(public readonly int $reminderId) {}

    /**
     * @throws Throwable when Telegram refuses the send, so the job retries
     */
    public function handle(BotMessenger $messenger, BotButtons $buttons): void
    {
        DB::transaction(function () use ($messenger, $buttons): void {
            /** @var ReminderDispatch|null $reminder */
            $reminder = ReminderDispatch::query()
                ->lockForUpdate()
                ->with(['participant.user', 'period.challenge'])
                ->find($this->reminderId);

            if ($reminder === null || $reminder->isSent()) {
                // Deleted with the participant, or already dealt with by a
                // duplicate of this job — either way, nothing to do.
                return;
            }

            if ($this->suppressed($reminder)) {
                $this->stamp($reminder);

                return;
            }

            $user = $reminder->participant->user;

            $messenger->paragraphs(
                $user,
                [$this->compose($messenger, $reminder->period, $reminder)],
                // The nudge used to name `/checkin`, which is an instruction
                // only a user who already knows the command can follow. The
                // button is that instruction, and it names the challenge because
                // a participant is usually in more than one.
                [[$buttons->checkIn($user, $reminder->participant->challenge)]],
            );

            $this->stamp($reminder);
        });
    }

    /**
     * Whether this nudge should be counted without being sent.
     *
     * Two suppressions, both checked at send time rather than at scheduling
     * time, because both can change between the two: the participant checks in
     * after the row was minted, and the rollover closes the period.
     */
    private function suppressed(ReminderDispatch $reminder): bool
    {
        if ($reminder->period->isRolledOver()) {
            // The sweep got there first. A "period ending!" for a period that
            // has ended is not a reminder, it is a taunt.
            return true;
        }

        if ($reminder->kind->skipWhenSettled()) {
            /** @var CheckIn|null $checkIn */
            $checkIn = CheckIn::query()
                ->where('challenge_participant_id', $reminder->challenge_participant_id)
                ->where('challenge_period_id', $reminder->challenge_period_id)
                ->first();

            if ($checkIn !== null && $checkIn->status->isSettled()) {
                // Done participants do not need telling the period is ending.
                // `isSettled()` rather than `Approved`: a frozen or missed
                // period is equally decided, and the verdict is not this
                // nudge's business.
                return true;
            }
        }

        return false;
    }

    /**
     * The message, from the period as it stands.
     */
    private function compose(BotMessenger $messenger, ChallengePeriod $period, ReminderDispatch $reminder): string
    {
        $challenge = $period->challenge;
        $user = $reminder->participant->user;

        return $messenger->line($user, "bot.reminder.{$reminder->kind->value}", [
            'title' => $challenge->title,
            'index' => $period->index + 1,
            'total' => $challenge->total_periods,
            // The boundary the participant experiences, in the challenge's own
            // timezone — the same clock the period was cut on, not the worker's.
            'moment' => $this->moment($period, $reminder->kind),
            'timezone' => $challenge->timezone,
        ]);
    }

    /**
     * The boundary this kind talks about, in the challenge's timezone.
     *
     * `challenge_starting` and `period_opened` are about a start; `period_ending`
     * is about the close. The line renders whatever is passed as `:moment`, so
     * each kind names the moment its sentence is actually about.
     */
    private function moment(ChallengePeriod $period, ReminderKind $kind): string
    {
        $boundary = $kind === ReminderKind::PeriodEnding
            ? $period->ends_at
            : $period->starts_at;

        return $boundary->timezone($period->challenge->timezone)->format('Y-m-d H:i');
    }

    private function stamp(ReminderDispatch $reminder): void
    {
        $reminder->update(['sent_at' => now()]);
    }
}
