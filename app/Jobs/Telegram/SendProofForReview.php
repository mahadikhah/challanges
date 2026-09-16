<?php

namespace App\Jobs\Telegram;

use App\Enums\CheckInStatus;
use App\Models\CheckIn;
use App\Services\Telegram\ProofReviewNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tell a challenge's creator that a proof is waiting on them — once.
 *
 * The send half of the review notification, and the only part that talks to
 * Telegram. It carries an id rather than a model and re-reads the row at
 * execution time, because the world moves between dispatch and delivery: a
 * creator may have approved from the admin queue, the sweep may have missed
 * the period, a second worker may have settled it. Only a check-in still
 * sitting at `Submitted` is one a human is actually owed.
 *
 * A failure is worth retrying — the platform refusing a send is usually
 * momentary — but not forever: the admin review queue already shows the
 * submission, so a notification that never lands costs the creator a
 * convenience, not the review.
 */
class SendProofForReview implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How many times a flaky send may be retried before the notification is
     * left unsaid rather than hammered.
     */
    public int $tries = 3;

    public function __construct(public readonly int $checkInId) {}

    public function handle(ProofReviewNotifier $notifier): void
    {
        /** @var CheckIn|null $checkIn */
        $checkIn = CheckIn::query()
            ->with(['participant.user', 'period.challenge.creator'])
            ->find($this->checkInId);

        if ($checkIn === null || $checkIn->status !== CheckInStatus::Submitted) {
            // Already decided in the time it took to get here — a creator's
            // tap, an admin's, or a settlement. The notification the dispatch
            // promised is no longer one we owe.
            return;
        }

        $notifier->notify($checkIn);
    }
}
