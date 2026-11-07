<?php

namespace App\Jobs\Challenges;

use App\Models\Challenge;
use App\Services\Telegram\ChannelBroadcaster;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Post a public challenge to the announcement channel.
 *
 * Queued rather than inline because it is a Bot API call on the tail of a
 * transaction: creating a challenge must not fail, or hold row locks, because
 * Telegram is slow or briefly down.
 *
 * **`announced_at` is the idempotency key.** Set inside the same statement that
 * claims the work, so a retried job, a second dispatch, or two workers racing the
 * same challenge produce exactly one post rather than one per attempt. The order
 * matters and is the reverse of the usual: claim first, then post. Posting first
 * and stamping after would double-post on any failure between the two, and a
 * duplicate announcement cannot be recalled — where a missed one is visible in
 * `Challenge::awaitsAnnouncement()` and can be re-dispatched.
 */
class AnnounceChallenge implements ShouldQueue
{
    use Queueable;

    /**
     * Two attempts and then stop. A challenge that could not be announced is not
     * broken — `awaitsAnnouncement()` still reports it, so it can be retried
     * deliberately rather than by a queue that keeps trying past the point where
     * the post would still be timely.
     */
    public int $tries = 2;

    /**
     * @var list<int>
     */
    public array $backoff = [30];

    public function __construct(public readonly Challenge $challenge) {}

    public function handle(ChannelBroadcaster $channel): void
    {
        $channel->announce($this->challenge);
    }
}
