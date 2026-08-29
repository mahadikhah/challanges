<?php

namespace App\Services\Telegram;

use App\Actions\CheckIns\IssueCheckInPhrase;
use App\Actions\CheckIns\OpenCheckIn;
use App\Actions\CheckIns\SubmitCheckIn;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\CheckInRejection;
use App\Enums\ConversationState;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Exceptions\CheckInRejectedException;
use App\Models\BotConversation;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\Callbacks\CheckInCallback;
use App\Services\Telegram\Callbacks\ReviewCheckInCallback;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The check-in conversation: what `/checkin` starts and the typed answer or
 * photo finishes.
 *
 * Everything the participant does here lands in `SubmitCheckIn` — the same
 * action the Mini App and the admin panel will call — so this class owns no rule
 * about who may check in or what counts as proof. What it owns is the *surface*:
 * listing what is owed, prompting per proof type, holding the one piece of state
 * the proof needs (which challenge a typed phrase or an incoming photo answers),
 * and turning a `CheckInRejectedException` into the right sentence.
 *
 * **Button challenges never open a conversation.** Their proof is a tap, so the
 * button on the listing *is* the submission. Text and photo challenges need a
 * follow-up message from the participant, and a follow-up message needs a
 * `BotConversation` row to belong to — one per user, which is why starting a
 * check-in replaces any wizard that was half-built. Deliberate and rare: a
 * participant reaches for `/checkin` when the thing they owe is now, and a
 * half-built challenge is recoverable with `/create`.
 *
 * **The creator is notified, not polled.** A photo is worth nothing until the
 * creator looks at it, so the submission message carries the review buttons —
 * the creator's tap goes to `ReviewCheckInCallback`, which re-derives ownership
 * from the row and the actor exactly as `ReviewCheckIn` insists.
 *
 * **A rejection does not end the flow when the mechanic is still working.** A
 * mismatched phrase is the phrase mechanic doing its job — the participant is
 * re-asked, not dropped. Every other refusal is a state change the participant
 * cannot undo by trying again, so the conversation is closed and the reason said
 * plainly.
 */
class CheckInFlow
{
    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly SubmitCheckIn $submit,
        private readonly IssueCheckInPhrase $phrases,
        private readonly OpenCheckIn $open,
        private readonly TelegramFileDownloader $files,
        private readonly BotMessenger $messenger,
        private readonly Settings $settings,
    ) {}

    /**
     * `/checkin` — say what is owed, with the button that owes it.
     */
    public function begin(User $user): void
    {
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        $participations = $user->participations()->active()->get();

        if ($participations->isEmpty()) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.none'));

            return;
        }

        $now = CarbonImmutable::now();
        $lines = [];
        $buttons = [];

        foreach ($participations as $participant) {
            $challenge = $participant->challenge;

            if (! $challenge->status->acceptsCheckIns()) {
                continue;
            }

            $period = $challenge->periods()->containing($now)->first();

            if ($period === null || ! $participant->owesPeriod($period)) {
                continue;
            }

            $checkIn = $this->open->handle($participant, $period);

            if ($checkIn->status->isSettled()) {
                $lines[] = $this->messenger->line($user, 'bot.checkin.done', [
                    'title' => $challenge->title,
                    'streak' => $participant->current_streak,
                ]);

                continue;
            }

            if ($checkIn->status->awaitsReview()) {
                $lines[] = $this->messenger->line($user, 'bot.checkin.awaiting_review', [
                    'title' => $challenge->title,
                ]);

                continue;
            }

            $lines[] = $this->messenger->line($user, 'bot.checkin.todo', [
                'title' => $challenge->title,
                'index' => $period->index + 1,
                'total' => $challenge->total_periods,
            ]);

            $buttons[] = [
                'text' => $this->messenger->line($user, 'bot.checkin.button', [
                    'title' => $challenge->title,
                ]),
                'callback_data' => BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token),
            ];
        }

        if ($lines === []) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.nothing_due'));

            return;
        }

        $this->messenger->paragraphs($user, $lines, $buttons === [] ? null : [$buttons]);
    }

    /**
     * A tap on a challenge's check-in button.
     *
     * For `button` challenges the tap *is* the proof, so it is submitted here and
     * now. For the other two it opens the conversation the proof arrives through.
     */
    public function start(User $user, Challenge $challenge): void
    {
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        // A recording-proof challenge opens the conversation its recording
        // arrives through — voice and video share the review fate a photo has,
        // so they share this flow's shape: the prompt names the cap, the
        // recording lands in `SubmitCheckIn::uploadVoice()/uploadVideo()`, and
        // the creator gets the verdict buttons on the message after.
        match ($challenge->proof_type) {
            ProofType::Button => $this->tap($user, $challenge),
            ProofType::TextAutogen => $this->askPhrase($user, $challenge),
            ProofType::ImageApproval => $this->askPhoto($user, $challenge),
            ProofType::VoiceApproval => $this->askVoice($user, $challenge),
            ProofType::VideoApproval => $this->askVideo($user, $challenge),
        };
    }

    /**
     * The typed phrase, answering the challenge the conversation holds.
     */
    public function receiveText(User $user, BotConversation $conversation, ?string $text): void
    {
        $this->assertOwnState($conversation, ConversationState::AwaitingCheckInText);

        if ($text === null) {
            // A photo is not the phrase. Re-asked rather than refused, because
            // the thing they were asked for is one message away.
            $this->reaskPhrase($user, $conversation);

            return;
        }

        $challenge = $this->challengeOf($conversation);

        if ($challenge === null) {
            $this->abandon($user, $conversation, 'bot.fallback.stale_button');

            return;
        }

        try {
            $checkIn = $this->submit->typePhrase($user, $challenge, $text);
        } catch (CheckInRejectedException $refused) {
            if ($refused->reason === CheckInRejection::PhraseMismatch) {
                // The mechanic working, not a failure. The conversation stays
                // open: the phrase is still on their screen one scroll up.
                $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.phrase_error'));

                return;
            }

            $this->abandon($user, $conversation, "bot.checkin.refused.{$refused->reason->value}", [
                'title' => $challenge->title,
            ]);

            return;
        }

        $this->abandon($user, $conversation);
        $this->confirm($user, $challenge, $checkIn);
    }

    /**
     * The photo, answering the challenge the conversation holds.
     */
    public function receivePhoto(User $user, BotConversation $conversation, mixed $photo): void
    {
        $this->assertOwnState($conversation, ConversationState::AwaitingCheckInPhoto);

        if (! is_array($photo) || $photo === []) {
            $this->reaskPhoto($user, $conversation);

            return;
        }

        $challenge = $this->challengeOf($conversation);

        if ($challenge === null) {
            $this->abandon($user, $conversation, 'bot.fallback.stale_button');

            return;
        }

        try {
            $path = $this->files->downloadPhoto($user->platform, $photo);
            $checkIn = $this->submit->uploadPhoto($user, $challenge, $path);
        } catch (CheckInRejectedException $refused) {
            $this->abandon($user, $conversation, "bot.checkin.refused.{$refused->reason->value}", [
                'title' => $challenge->title,
            ]);

            return;
        } catch (Throwable $failure) {
            // A Telegram or storage failure is ours, not theirs. The conversation
            // stays open so the queue's retry — or their second attempt — is not
            // met with "send /create".
            Log::error('A check-in photo could not be stored.', [
                'user_id' => $user->getKey(),
                'challenge_id' => $challenge->getKey(),
                'reason' => $failure->getMessage(),
            ]);

            $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.photo_error'));

            return;
        }

        $this->abandon($user, $conversation);
        $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.photo_sent', [
            'title' => $challenge->title,
        ]));

        $this->notifyReviewer($user, $challenge, $checkIn);
    }

    /**
     * The voice message, answering the challenge the conversation holds.
     *
     * @param  array<array-key, mixed>|null  $voice  the message's `voice` object
     */
    public function receiveVoice(User $user, BotConversation $conversation, ?array $voice): void
    {
        $this->receiveRecording($user, $conversation, ConversationState::AwaitingCheckInVoice, $voice, 'voice');
    }

    /**
     * The video message, answering the challenge the conversation holds.
     *
     * @param  array<array-key, mixed>|null  $video  the message's `video` object
     */
    public function receiveVideo(User $user, BotConversation $conversation, ?array $video): void
    {
        $this->receiveRecording($user, $conversation, ConversationState::AwaitingCheckInVideo, $video, 'video');
    }

    /**
     * The recording — voice or video — that answers the conversation's
     * challenge. The photo's mirror with two additions the caps demand: the
     * duration and size the messenger itself measured ride along to
     * `SubmitCheckIn`, which refuses an over-cap recording before anything is
     * written.
     *
     * @param  array<array-key, mixed>|null  $payload  the message's `voice`/`video` object
     * @param  'voice'|'video'  $kind
     */
    private function receiveRecording(User $user, BotConversation $conversation, ConversationState $state, ?array $payload, string $kind): void
    {
        $this->assertOwnState($conversation, $state);

        if (! is_array($payload) || ! is_string($payload['file_id'] ?? null) || $payload['file_id'] === '') {
            $this->reask($user, $conversation, "bot.checkin.{$kind}_expected");

            return;
        }

        $challenge = $this->challengeOf($conversation);

        if ($challenge === null) {
            $this->abandon($user, $conversation, 'bot.fallback.stale_button');

            return;
        }

        try {
            $path = $kind === 'voice'
                ? $this->files->downloadVoice($user->platform, $payload)
                : $this->files->downloadVideo($user->platform, $payload);

            $checkIn = $this->submit->{$kind === 'voice' ? 'uploadVoice' : 'uploadVideo'}(
                $user,
                $challenge,
                $path,
                (int) ($payload['duration'] ?? 0),
                $this->sizeInKb($payload),
            );
        } catch (CheckInRejectedException $refused) {
            if ($refused->reason === CheckInRejection::MediaTooLong || $refused->reason === CheckInRejection::MediaTooLarge) {
                // A cap refusal is the mechanic working, not the flow failing:
                // a shorter or smaller recording is one message away, so the
                // conversation stays open exactly as a mismatched phrase does.
                $this->reask($user, $conversation, "bot.checkin.refused.{$refused->reason->value}", [
                    'title' => $challenge->title,
                ]);

                return;
            }

            $this->abandon($user, $conversation, "bot.checkin.refused.{$refused->reason->value}", [
                'title' => $challenge->title,
            ]);

            return;
        } catch (Throwable $failure) {
            // A Telegram or storage failure is ours, not theirs — same doctrine
            // as the photo: the conversation stays open for the retry.
            Log::error("A check-in {$kind} could not be stored.", [
                'user_id' => $user->getKey(),
                'challenge_id' => $challenge->getKey(),
                'reason' => $failure->getMessage(),
            ]);

            $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.recording_error'));

            return;
        }

        $this->abandon($user, $conversation);
        $this->messenger->send($user, $this->messenger->line($user, "bot.checkin.{$kind}_sent", [
            'title' => $challenge->title,
        ]));

        $this->notifyReviewer($user, $challenge, $checkIn);
    }

    /**
     * The payload's `file_size` — bytes, the messenger's own count — as whole
     * kilobytes, or null when the messenger did not state one.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function sizeInKb(array $payload): ?int
    {
        $bytes = $payload['file_size'] ?? null;

        return is_int($bytes) || is_string($bytes) && ctype_digit($bytes)
            ? intdiv((int) $bytes, 1024)
            : null;
    }

    /**
     * One tap, one approved check-in.
     */
    private function tap(User $user, Challenge $challenge): void
    {
        try {
            $checkIn = $this->submit->tap($user, $challenge);
        } catch (CheckInRejectedException $refused) {
            $this->messenger->send($user, $this->messenger->line(
                $user,
                "bot.checkin.refused.{$refused->reason->value}",
                ['title' => $challenge->title],
            ));

            return;
        }

        $this->confirm($user, $challenge, $checkIn);
    }

    /**
     * Show the participant their phrase and open the flow that answers it.
     */
    private function askPhrase(User $user, Challenge $challenge): void
    {
        try {
            [$participant, $period] = $this->obligation($user, $challenge);
        } catch (CheckInRejectedException $refused) {
            $this->refuse($user, $refused, $challenge);

            return;
        }

        // Issued here rather than on submission, so the string the participant
        // reads is the string their answer is compared against — generated once,
        // persisted, never recomputed.
        $checkIn = $this->phrases->forParticipant($participant, $period);

        $this->openConversation($user, ConversationState::AwaitingCheckInText, $challenge);

        $this->messenger->paragraphs($user, [
            $this->messenger->line($user, 'bot.checkin.phrase_prompt', ['title' => $challenge->title]),
            $checkIn->expected_phrase,
        ]);
    }

    /**
     * Ask for the photo and open the flow that answers it.
     */
    private function askPhoto(User $user, Challenge $challenge): void
    {
        try {
            $this->obligation($user, $challenge);
        } catch (CheckInRejectedException $refused) {
            $this->refuse($user, $refused, $challenge);

            return;
        }

        $this->openConversation($user, ConversationState::AwaitingCheckInPhoto, $challenge);

        $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.photo_prompt', [
            'title' => $challenge->title,
        ]));
    }

    /**
     * Ask for the voice recording and open the flow that answers it. The cap
     * is named up front: an honest prompt is cheaper than a refusal.
     */
    private function askVoice(User $user, Challenge $challenge): void
    {
        $this->askRecording($user, $challenge, ConversationState::AwaitingCheckInVoice, 'voice');
    }

    /**
     * Ask for the video recording and open the flow that answers it.
     */
    private function askVideo(User $user, Challenge $challenge): void
    {
        $this->askRecording($user, $challenge, ConversationState::AwaitingCheckInVideo, 'video');
    }

    /**
     * @param  'voice'|'video'  $kind
     */
    private function askRecording(User $user, Challenge $challenge, ConversationState $state, string $kind): void
    {
        try {
            $this->obligation($user, $challenge);
        } catch (CheckInRejectedException $refused) {
            $this->refuse($user, $refused, $challenge);

            return;
        }

        $this->openConversation($user, $state, $challenge);

        $this->messenger->send($user, $this->messenger->line($user, "bot.checkin.{$kind}_prompt", [
            'title' => $challenge->title,
            'max' => $challenge->proof_media_max_seconds,
            'size' => $challenge->proof_media_max_size_kb,
        ]));
    }

    /**
     * The participant and period the proof would land against, or why not.
     *
     * A trimmed-down mirror of `SubmitCheckIn`'s own guards — enough to pick the
     * right sentence before anything is written; the authoritative check is still
     * the one inside the action, re-made on submission.
     *
     * @return array{0: ChallengeParticipant, 1: ChallengePeriod}
     *
     * @throws CheckInRejectedException
     */
    private function obligation(User $user, Challenge $challenge): array
    {
        if (! $challenge->status->acceptsCheckIns()) {
            throw CheckInRejectedException::challengeClosed($challenge);
        }

        $participant = $challenge->participants()->where('user_id', $user->getKey())->first();

        if ($participant === null || ! $participant->status->owesCheckIns()) {
            throw CheckInRejectedException::notAParticipant($challenge);
        }

        $period = $challenge->periods()->containing(CarbonImmutable::now())->first();

        if ($period === null) {
            throw CheckInRejectedException::noOpenPeriod($challenge);
        }

        return [$participant, $period];
    }

    /**
     * Open (or replace) the conversation holding which challenge is being proved.
     */
    private function openConversation(User $user, ConversationState $state, Challenge $challenge): BotConversation
    {
        return BotConversation::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'state' => $state,
                'payload' => ['challenge_id' => $challenge->getKey()],
                'expires_at' => now()->addMinutes($this->settings->integer(SettingKey::ConversationTtlMinutes)),
            ],
        );
    }

    /**
     * The challenge this conversation is proving, or null when it has vanished.
     */
    private function challengeOf(BotConversation $conversation): ?Challenge
    {
        $challengeId = $conversation->answer('challenge_id');

        if (! is_int($challengeId) && ! is_string($challengeId)) {
            return null;
        }

        return Challenge::query()->find($challengeId);
    }

    /**
     * Say a check-in landed, with the streak it earned.
     */
    private function confirm(User $user, Challenge $challenge, CheckIn $checkIn): void
    {
        // Refreshed, because `SettleCheckIn` moves the streak on a freshly locked
        // row while the relation hanging off this instance still holds the
        // pre-settlement count — reporting "streak: 0" on the day it became 1.
        $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.confirmed', [
            'title' => $challenge->title,
            'streak' => $checkIn->participant->refresh()->current_streak,
        ]));
    }

    /**
     * Hand a submitted proof to the creator, with the verdict buttons on it.
     *
     * The message names what kind of thing to review; the thing itself is
     * previewed in the admin panel's review queue — the bot has no way to
     * attach a stored recording to a message without re-uploading it, and the
     * queue is where a verdict can be considered anyway.
     */
    private function notifyReviewer(User $participant, Challenge $challenge, CheckIn $checkIn): void
    {
        $creator = $challenge->creator;

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

        $kind = $checkIn->proofKind() ?? 'image';

        $this->messenger->paragraphs($creator, [
            $this->messenger->line($creator, "bot.checkin.review_prompt_{$kind}", [
                'name' => $participant->first_name ?? $participant->name,
                'title' => $challenge->title,
            ]),
        ], [
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
        ]);
    }

    /**
     * Close the conversation, optionally saying why.
     *
     * @param  array<string, string|int|float>  $replace
     */
    private function abandon(User $user, BotConversation $conversation, ?string $line = null, array $replace = []): void
    {
        $conversation->delete();

        if ($line !== null) {
            $this->messenger->send($user, $this->messenger->line($user, $line, $replace));
        }
    }

    /**
     * Answer a refusal that happened before anything was written.
     */
    private function refuse(User $user, CheckInRejectedException $refused, Challenge $challenge): void
    {
        $this->messenger->send($user, $this->messenger->line(
            $user,
            "bot.checkin.refused.{$refused->reason->value}",
            ['title' => $challenge->title],
        ));
    }

    private function reaskPhrase(User $user, BotConversation $conversation): void
    {
        $this->reask($user, $conversation, 'bot.checkin.phrase_expected');
    }

    private function reaskPhoto(User $user, BotConversation $conversation): void
    {
        $this->reask($user, $conversation, 'bot.checkin.photo_expected');
    }

    /**
     * Re-ask the proof without re-issuing anything: the phrase was generated
     * once and the photo prompt needs no state, so the conversation row is all
     * there is to say again.
     *
     * @param  array<string, string>  $replace
     */
    private function reask(User $user, BotConversation $conversation, string $line, array $replace = []): void
    {
        $conversation->forceFill(['expires_at' => now()->addMinutes($this->settings->integer(SettingKey::ConversationTtlMinutes))])->save();

        $this->messenger->send($user, $this->messenger->line($user, $line, $replace));
    }

    /**
     * @throws LogicException when the conversation is not this flow's
     */
    private function assertOwnState(BotConversation $conversation, ConversationState $expected): void
    {
        if ($conversation->state !== $expected) {
            throw new LogicException("{$conversation->state->value} is not a check-in state this flow owns.");
        }
    }
}
