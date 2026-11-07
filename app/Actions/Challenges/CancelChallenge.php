<?php

namespace App\Actions\Challenges;

use App\Enums\ChallengeStatus;
use App\Enums\ParticipantStatus;
use App\Exceptions\ChallengeNotCancellable;
use App\Jobs\Telegram\SendBotMessage;
use App\Models\Challenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stop a challenge early.
 *
 * Cancellation is the moderation lever for challenges that need to end — a
 * spam title, an abandoned experiment, a creator who asked for help — and it is
 * a one-way door: terminal statuses are never transitioned out of, so this
 * action is a deliberate write against the lifecycle, not a toggle. Cancelling
 * does not un-join anybody, un-approve a check-in or claw anything back; it
 * stops obligations from accruing and leaves history as it stands, which is
 * exactly what an audit trail should do.
 *
 * Participants are told by the bot, not synchronously. A challenge can have
 * hundreds of them and Telegram allows roughly a message a second per chat, so
 * the notifications fan out as staggered queued jobs inside the same
 * transaction — they only dispatch if the cancellation itself commits.
 *
 * **Authorization is derived, not asked.** The actor must be the challenge's
 * creator or a platform admin, decided from the row — the same shape
 * `ReviewCheckIn` uses, and for the same reason: no caller can pass a
 * permission alongside an id.
 */
class CancelChallenge
{
    /**
     * Seconds between one queued notification and the next. One, deliberately:
     * matches `DispatchDueReminders`' stagger, which was sized for these
     * rate limits.
     */
    public const STAGGER_SECONDS = 1;

    public function handle(User $actor, Challenge $challenge): Challenge
    {
        if (! $actor->is_admin && $challenge->creator_id !== $actor->getKey()) {
            throw ChallengeNotCancellable::notPermitted($challenge);
        }

        // Re-read inside the transaction so two simultaneous cancellations —
        // the creator's and an admin's — cannot both "succeed" against a row
        // that is about to be terminal, and so a challenge that completed
        // between the controller's fetch and this call is refused on fact.
        return DB::transaction(function () use ($challenge): Challenge {
            $challenge->refresh();

            if ($challenge->status->isTerminal()) {
                throw ChallengeNotCancellable::terminal($challenge);
            }

            $challenge->update(['status' => ChallengeStatus::Cancelled]);

            $this->notifyParticipants($challenge);

            return $challenge->refresh();
        });
    }

    /**
     * Queue the "this challenge is over" line, one job per participant, spaced
     * apart so the fan-out obeys Telegram's per-chat rate limit.
     *
     * Only `active` participants hear it — somebody who left was done being
     * told things, and a `removed` one already had their last word.
     */
    private function notifyParticipants(Challenge $challenge): void
    {
        $userIds = $challenge->participants()
            ->where('status', ParticipantStatus::Active)
            ->pluck('user_id');

        foreach ($userIds as $position => $userId) {
            SendBotMessage::dispatch($userId, 'bot.challenge.cancelled', [
                'title' => $challenge->title,
            ])->delay($position * self::STAGGER_SECONDS);
        }
    }
}
