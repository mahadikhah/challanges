<?php

namespace App\Events;

use App\Models\CheckIn;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A check-in was left undecided for a human — the manual queue's entrance.
 *
 * The mirror image of `CheckInSettled`: that one fires when a decision was
 * made, this one when one is still owed. Fired by `AdvanceCheckInStep` when an
 * AI verdict falls back rather than settling the row, so the one flow that
 * leaves a check-in `Submitted` without a creator ever hearing about it has
 * somewhere to say so.
 *
 * Dispatched **outside** the transaction that opened the row, which is where
 * `AdvanceCheckInStep` already stands when it learns the verdict's outcome. A
 * listener here therefore cannot hold the session's lock, and a rollback takes
 * the event with it — nothing is announced about a check-in that never was.
 *
 * The whole check-in is carried rather than an id: listeners are plain classes
 * here, not queued listeners, so there is no serialization to pay for and no
 * re-read to race. The queued job that follows re-reads the world itself.
 */
class CheckInAwaitsReview
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly CheckIn $checkIn) {}
}
