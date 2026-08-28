<?php

namespace App\Jobs\Telegram;

use App\Models\User;
use App\Services\Telegram\BotMessenger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Send one bot line to one user, on the queue.
 *
 * The fan-out half of anything that is not a reply — a cancelled challenge
 * telling its participants, and later whatever else the platform needs to say to
 * many people at once. Replies answer the update that triggered them and may
 * send inline; announcements cannot, because Telegram rate-limits to roughly a
 * message a second per chat, so the caller staggers copies of this job with
 * `delay()` the way `DispatchDueReminders` does.
 *
 * The user is re-resolved at send time, by id from our own rows — never a chat
 * id carried on the job. A user deleted between dispatch and send is a user who
 * no longer needs telling, so the job ends quietly rather than retrying a
 * message nobody can receive.
 */
class SendBotMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How many times a flaky Telegram send may be retried before the message is
     * left undelivered rather than looped forever.
     */
    public int $tries = 5;

    /**
     * @param  array<string, string|int|float>  $replace
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $line,
        public readonly array $replace = [],
    ) {}

    /**
     * @throws Throwable when Telegram refuses the send, so the job retries
     */
    public function handle(BotMessenger $messenger): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || $user->platform_user_id === null) {
            // Deleted, or a web-only user the bot could never reach. Either way
            // there is no chat to deliver into.
            return;
        }

        $messenger->send($user, $messenger->line($user, $this->line, $this->replace));
    }
}
