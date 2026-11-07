<?php

namespace App\Services\Telegram;

use App\Messaging\Contracts\MessengerException;
use App\Models\CheckIn;
use App\Models\User;
use App\Services\Telegram\Callbacks\ReviewCheckInCallback;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Hand a submitted proof to the challenge's creator, verdict buttons on it.
 *
 * One collaborator rather than one private method per flow, because two
 * arrivals reach the same place: a photo uploaded through the simple flow, and
 * a timed session whose final step was submitted for review. The rule that a
 * creator sees the proof itself — not a description of it — is one rule, and
 * a second copy of it would drift the first time either was touched.
 *
 * The proof rides along: a photo, a voice note or a video goes out as the
 * media message, with the approve/reject buttons on its caption, so the
 * creator decides from the thing rather than from a description of it. One
 * send, not two — the messenger allows roughly a message a second per chat, and
 * the second send is the one that gets refused.
 *
 * When the media cannot go out — no file stored, an extension the upload
 * pipeline does not write, the file gone from disk, or the platform refusing
 * the upload — the creator still gets the sentence and the buttons, because a
 * creator who can approve from a text message is strictly better served than
 * one who hears nothing. The admin review queue remains the backstop either
 * way.
 *
 * Who the creator *is* is resolved here from the challenge, never from an
 * argument: a caller that could name the recipient could name the wrong one.
 */
class ProofReviewNotifier
{
    public function __construct(private readonly BotMessenger $messenger) {}

    /**
     * Tell the challenge's creator that this check-in is waiting on them.
     *
     * A caller owes nothing to the return value and nothing to the outcome: a
     * creator who cannot be reached is a log line, and a media send that fails
     * degrades to the text instead of failing the caller's transaction.
     */
    public function notify(CheckIn $checkIn): void
    {
        $challenge = $checkIn->period->challenge;
        $creator = $challenge->creator;
        $participant = $checkIn->participant->user;

        if ($creator->platform_user_id === null) {
            // An email-only admin created this (an import, say). BotMessenger
            // cannot reach them and must not try — the queue is already on the
            // submission, so this is a log line rather than a lost review.
            Log::info('A check-in proof awaits a creator the bot cannot message.', [
                'check_in_id' => $checkIn->getKey(),
                'creator_id' => $creator->getKey(),
            ]);

            return;
        }

        // `image` when the row carries no readable extension. A creator handed
        // the sentence and the buttons can still decide; one handed nothing
        // cannot, and the sentence is the thing that must not be lost.
        $kind = $checkIn->proofKind() ?? 'image';

        $lines = [
            $this->messenger->line($creator, "bot.checkin.review_prompt_{$kind}", [
                'name' => $participant->first_name ?? $participant->name,
                'title' => $challenge->title,
            ]),
        ];

        $keyboard = $this->verdictButtons($creator, $checkIn);

        if (! $this->sendProofMedia($creator, $checkIn, $lines, $keyboard)) {
            $this->messenger->paragraphs($creator, $lines, $keyboard);
        }
    }

    /**
     * The approve/reject row, in the creator's own language.
     *
     * The callback data is the shared `ReviewCheckInCallback`'s, so a tap here
     * settles the row through exactly the code a simple-flow tap uses — there
     * is one verdict engine, and this only mints its buttons.
     *
     * @return list<list<array<string, string>>>
     */
    private function verdictButtons(User $creator, CheckIn $checkIn): array
    {
        return [
            [
                [
                    'text' => $this->messenger->line($creator, 'bot.checkin.approve_button'),
                    'callback_data' => BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::APPROVE),
                ],
                [
                    'text' => $this->messenger->line($creator, 'bot.checkin.reject_button'),
                    'callback_data' => BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::REJECT),
                ],
            ],
        ];
    }

    /**
     * Send the stored proof to the creator as media, buttons on the caption.
     *
     * @param  list<string|null>  $lines
     * @param  list<list<array<string, string>>>  $keyboard
     * @return bool false when the media could not go out and the caller should fall back to text
     */
    private function sendProofMedia(User $creator, CheckIn $checkIn, array $lines, array $keyboard): bool
    {
        $path = $checkIn->proof_path;

        if ($path === null) {
            // A tap or a typed phrase: there is no file, and none was expected.
            return false;
        }

        $kind = $checkIn->proofKind();

        if ($kind === null) {
            Log::warning('A check-in proof has an extension the bot cannot send; the creator got the text instead.', [
                'check_in_id' => $checkIn->getKey(),
                'proof_path' => $path,
            ]);

            return false;
        }

        $bytes = Storage::disk('local')->get($path);

        if ($bytes === null) {
            Log::warning('A check-in proof is missing from disk; the creator got the text instead.', [
                'check_in_id' => $checkIn->getKey(),
                'proof_path' => $path,
            ]);

            return false;
        }

        try {
            // `basename` keeps the stored extension, which is what the platform
            // reads to pick a content type; the stored name is a random hash, so
            // nothing about the sender travels with it.
            $this->messenger->sendMedia($creator, $kind, $bytes, basename($path), $lines, $keyboard);

            return true;
        } catch (MessengerException $failure) {
            Log::warning('A check-in proof could not be sent to its creator; the text went instead.', [
                'check_in_id' => $checkIn->getKey(),
                'proof_path' => $path,
                'reason' => $failure->getMessage(),
            ]);

            return false;
        }
    }
}
