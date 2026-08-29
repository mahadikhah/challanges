<?php

namespace App\Jobs\Telegram;

use App\Enums\ChatPostKind;
use App\Enums\ProofType;
use App\Models\ChallengeChat;
use App\Models\CheckIn;
use App\Services\Telegram\ChatBroadcaster;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tell a linked chat that one participant checked in — once.
 *
 * The send half of the announcement pipeline, and the only part that talks to
 * Telegram. It is deliberately re-runnable: the event listener may fire twice
 * for one settlement (a retried webhook, an operator replay), so the job
 * claims its `ChallengeChatPost` row *inside* the send transaction and skips
 * quietly when the claim loses. The claim, the send and the world it re-checks
 * — the chat still active, the row still approved, the settings still on —
 * are all read at execution time, not carried from dispatch.
 *
 * **What the message may contain is the privacy rule, verbatim: participant
 * display name, period number, streak count — and the approved photo only
 * when `share_proof_media` is on.** A button tap or a matching phrase has no
 * proof to show and announces as text whatever `proof_is_public` says,
 * because `share_proof_media` cannot be true on a challenge whose proofs are
 * private in the first place — and the job re-checks that anyway rather than
 * trusting the flag.
 */
class PostCheckInAnnouncement implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How many times a flaky send may be retried before the announcement is
     * left unsaid rather than hammered.
     */
    public int $tries = 5;

    public function __construct(
        public readonly int $chatId,
        public readonly int $checkInId,
    ) {}

    /**
     * @throws Throwable when Telegram refuses the send, so the job retries
     */
    public function handle(ChatBroadcaster $broadcaster): void
    {
        /** @var ChallengeChat|null $chat */
        $chat = ChallengeChat::query()->with('challenge.creator')->find($this->chatId);

        /** @var CheckIn|null $checkIn */
        $checkIn = CheckIn::query()->with(['participant.user', 'period.challenge'])->find($this->checkInId);

        if ($chat === null || $checkIn === null || ! $this->stillAnnounceable($chat, $checkIn)) {
            // The chat was unlinked, or the check-in was unsettled (a late
            // approval reversed, a review verdict changed). Either way the
            // announcement the dispatch promised is no longer one we owe.
            return;
        }

        DB::transaction(function () use ($broadcaster, $chat, $checkIn): void {
            if (! $broadcaster->claim($chat, ChatPostKind::CheckInAnnouncement->value, [
                'challenge_period_id' => $checkIn->challenge_period_id,
                'challenge_participant_id' => $checkIn->challenge_participant_id,
            ])) {
                return;
            }

            try {
                if ($this->shareableProof($chat, $checkIn) === null) {
                    $broadcaster->sendLines($chat, $this->lines($broadcaster, $chat, $checkIn));
                } else {
                    $broadcaster->sendPhoto(
                        $chat,
                        (string) $checkIn->proof_path,
                        $this->lines($broadcaster, $chat, $checkIn),
                    );
                }
            } catch (Throwable $failure) {
                // Release the claim with the throw, so the queue's retry is
                // not silently swallowed by our own row.
                $chat->posts()
                    ->where('post_kind', ChatPostKind::CheckInAnnouncement)
                    ->where('challenge_period_id', $checkIn->challenge_period_id)
                    ->where('challenge_participant_id', $checkIn->challenge_participant_id)
                    ->delete();

                throw $failure;
            }
        });
    }

    /**
     * Whether the world still wants this announcement.
     */
    private function stillAnnounceable(ChallengeChat $chat, CheckIn $checkIn): bool
    {
        return $chat->is_active
            && $chat->post_checkin_announcements
            && $chat->challenge_id === $checkIn->period->challenge_id
            && $checkIn->status->incrementsStreak();
    }

    /**
     * The photo this chat may see, or null when there is nothing to attach.
     *
     * `share_proof_media` is the chat's opt-in, `sharesProofPublicly()` the
     * challenge's own exposure gate (`proof_is_public` **and** a proof type
     * that has media to share). Both are re-read here rather than trusted
     * from dispatch — a settings toggle between dispatch and execution must
     * not leak a private proof into a chat.
     */
    private function shareableProof(ChallengeChat $chat, CheckIn $checkIn): ?string
    {
        if (! $chat->share_proof_media || ! $chat->challenge->sharesProofPublicly()) {
            return null;
        }

        if ($chat->challenge->proof_type !== ProofType::ImageApproval || $checkIn->proof_path === null) {
            return null;
        }

        return $checkIn->proof_path;
    }

    /**
     * The announcement: name, period, streak — and nothing else. On a
     * quantity challenge the period's score rides along, because the number
     * is the whole point of the challenge; a binary challenge's format is
     * unchanged.
     *
     * @return list<string|null>
     */
    private function lines(ChatBroadcaster $broadcaster, ChallengeChat $chat, CheckIn $checkIn): array
    {
        $challenge = $checkIn->period->challenge;
        $participant = $checkIn->participant;

        $base = 'bot.chatpost.checkin';

        if ($challenge->scoring_type->isQuantity() && $checkIn->score !== null) {
            $base = "{$base}_scored";
        }

        return [
            $broadcaster->line($base, [
                'title' => $challenge->title,
                'name' => $participant->user->first_name ?? $participant->user->name,
                'period' => $checkIn->period->index + 1,
                'total' => $challenge->total_periods,
                'streak' => $participant->current_streak,
                'value' => $this->plainNumber($checkIn->reported_value),
                'unit' => (string) $challenge->unit_label,
                'score' => (int) $checkIn->score,
            ]),
        ];
    }

    /**
     * A stored two-decimal value shown without its trailing zeros — the
     * participant typed "45" and recognises that.
     */
    private function plainNumber(?string $stored): string
    {
        if ($stored === null) {
            return '0';
        }

        $trimmed = rtrim(rtrim($stored, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
