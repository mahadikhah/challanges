<?php

namespace App\Events;

use App\Models\CheckIn;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A check-in crossed into a settled status, exactly once.
 *
 * Fired by `SettleCheckIn` inside the settling transaction, only on the
 * transition — the same fact that moves the streak — so anything listening can
 * trust that one settled row means one event, however many times the caller
 * was retried. The whole check-in is carried rather than an id: listeners are
 * plain classes here, not queued listeners, so there is no serialization to
 * pay for and no re-read to race.
 */
class CheckInSettled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly CheckIn $checkIn) {}
}
