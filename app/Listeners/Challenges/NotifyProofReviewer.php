<?php

namespace App\Listeners\Challenges;

use App\Events\CheckInAwaitsReview;
use App\Jobs\Telegram\SendProofForReview;

/**
 * Queue one notification job when a check-in is left for a human to decide.
 *
 * A deliberately cheap body — no Telegram, no queries — so that whatever
 * raised the event is never held by the notification half of what it did.
 * Everything that could fail slowly, including the question of whether the
 * creator can still be reached, lives in the job.
 *
 * Unlike `AnnounceApprovedCheckIn` there is no fan-out to filter: a proof has
 * exactly one reviewer, and the job resolves which from the challenge.
 */
class NotifyProofReviewer
{
    public function handle(CheckInAwaitsReview $event): void
    {
        dispatch(new SendProofForReview($event->checkIn->getKey()));
    }
}
