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
use App\Messaging\Contracts\MessengerException;
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
use Illuminate\Support\Facades\Storage;
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
 * **A quantity challenge adds one question: "how many?"** Asked before the
 * proof for everything that settles on the spot — the tap, and the media
 * proofs whose AI verdict settles at upload — and after the phrase for a text
 * challenge, where the phrase proves presence and the number is the score. The
 * answer travels to `SubmitCheckIn`, which refuses to score a period without
 * one; the flow only decides when to ask.
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
        private readonly ReportedValue $values,
        private readonly CheckInConfirmation $confirmations,
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

        // A quantity challenge is judged on a number, so the number is asked
        // first for every proof that settles on the spot — the tap, and the
        // media proofs whose AI verdict settles at upload, before the evidence
        // exists. Text is the one exception: the phrase is what proves the
        // participant is present, so it is asked first and the number after.
        if ($challenge->scoring_type->isQuantity() && $challenge->proof_type !== ProofType::TextAutogen) {
            $this->askValue($user, $challenge, ['proof' => $challenge->proof_type->value]);

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
     *
     * On a quantity challenge the phrase proves presence and the number is the
     * score, so the phrase is remembered in the conversation and the number is
     * asked next — except on a retry, where the payload already holds the
     * number and this message is the corrected phrase.
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

        $reportedValue = $this->reportedValueOf($conversation);

        if ($challenge->scoring_type->isQuantity() && $reportedValue === null) {
            // The phrase matched nothing yet, but it is the answer to the
            // question on their screen — remembered verbatim and judged after
            // the number arrives, so the participant is asked one thing at a
            // time rather than a phrase and a total in the same breath.
            $this->openConversation($user, ConversationState::AwaitingCheckInValue, $challenge, [
                'proof' => ProofType::TextAutogen->value,
                'phrase' => $text,
            ]);
            $this->sendValuePrompt($user, $challenge);

            return;
        }

        try {
            $checkIn = $this->submit->typePhrase($user, $challenge, $text, reportedValue: $reportedValue);
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
     * The typed quantity report, answering the value question this flow asked.
     *
     * Where the answer goes depends on what proof the challenge takes: a tap
     * settles right here, a stashed phrase is judged with the number riding
     * along, and a media proof has its own question still to come — the
     * conversation moves on to the proof state carrying the number.
     */
    public function receiveValue(User $user, BotConversation $conversation, ?string $text): void
    {
        $this->assertOwnState($conversation, ConversationState::AwaitingCheckInValue);

        $challenge = $this->challengeOf($conversation);

        if ($challenge === null) {
            $this->abandon($user, $conversation, 'bot.fallback.stale_button');

            return;
        }

        $value = $this->values->normalise($text);

        if ($value === null) {
            // A decimal comma, Persian digits — all folded. Anything left that
            // is not a plain number is re-asked, not guessed at.
            $this->reask($user, $conversation, 'bot.checkin.value_error', [
                'unit' => (string) $challenge->unit_label,
            ]);

            return;
        }

        $proof = ProofType::tryFrom((string) $conversation->answer('proof', ''));

        if ($proof === null) {
            $this->abandon($user, $conversation, 'bot.fallback.stale_button');

            return;
        }

        match ($proof) {
            ProofType::Button => $this->tapValue($user, $conversation, $challenge, $value),
            ProofType::TextAutogen => $this->typePhraseValue($user, $conversation, $challenge, $value),
            default => $this->awaitProof($user, $conversation, $challenge, $proof, $value),
        };
    }

    /**
     * The number that completes a one-tap submission.
     */
    private function tapValue(User $user, BotConversation $conversation, Challenge $challenge, string $value): void
    {
        try {
            $checkIn = $this->submit->tap($user, $challenge, null, $value);
        } catch (CheckInRejectedException $refused) {
            $this->abandon($user, $conversation, "bot.checkin.refused.{$refused->reason->value}", [
                'title' => $challenge->title,
            ]);

            return;
        }

        $this->abandon($user, $conversation);
        $this->confirm($user, $challenge, $checkIn);
    }

    /**
     * The number that completes a phrase submission: the phrase was stashed
     * when the value was asked, and is judged now that both halves exist.
     */
    private function typePhraseValue(User $user, BotConversation $conversation, Challenge $challenge, string $value): void
    {
        $phrase = $conversation->answer('phrase');

        if (! is_string($phrase) || $phrase === '') {
            $this->abandon($user, $conversation, 'bot.fallback.stale_button');

            return;
        }

        try {
            $checkIn = $this->submit->typePhrase($user, $challenge, $phrase, reportedValue: $value);
        } catch (CheckInRejectedException $refused) {
            if ($refused->reason === CheckInRejection::PhraseMismatch) {
                // The number stays answered; only the phrase is re-asked. The
                // conversation returns to the text state carrying the value in
                // its payload, so the retry is one question, not two.
                $this->openConversation($user, ConversationState::AwaitingCheckInText, $challenge, [
                    'proof' => ProofType::TextAutogen->value,
                    'reported_value' => $value,
                ]);
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
     * The number that precedes a media proof: hand the conversation to the
     * proof state with the value in its payload, and ask for the media.
     */
    private function awaitProof(User $user, BotConversation $conversation, Challenge $challenge, ProofType $proof, string $value): void
    {
        $state = match ($proof) {
            ProofType::ImageApproval => ConversationState::AwaitingCheckInPhoto,
            ProofType::VoiceApproval => ConversationState::AwaitingCheckInVoice,
            default => ConversationState::AwaitingCheckInVideo,
        };

        $this->openConversation($user, $state, $challenge, ['reported_value' => $value]);

        $line = match ($proof) {
            ProofType::ImageApproval => 'bot.checkin.photo_prompt',
            default => 'bot.checkin.'.($proof === ProofType::VoiceApproval ? 'voice' : 'video').'_prompt',
        };

        $this->messenger->send($user, $this->messenger->line($user, $line, [
            'title' => $challenge->title,
            'max' => $challenge->proof_media_max_seconds,
            'size' => $challenge->proof_media_max_size_kb,
        ]));
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
            $checkIn = $this->submit->uploadPhoto($user, $challenge, $path, reportedValue: $this->reportedValueOf($conversation));
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
                reportedValue: $this->reportedValueOf($conversation),
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
     *
     * `$extra` rides in the payload for the states that need one more fact to
     * finish: which proof the value answers, the phrase stashed while the value
     * is asked, or the value stashed while the proof arrives.
     *
     * @param  array<string, string|int|float>  $extra
     */
    private function openConversation(User $user, ConversationState $state, Challenge $challenge, array $extra = []): BotConversation
    {
        return BotConversation::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'state' => $state,
                'payload' => array_merge(['challenge_id' => $challenge->getKey()], $extra),
                'expires_at' => now()->addMinutes($this->settings->integer(SettingKey::ConversationTtlMinutes)),
            ],
        );
    }

    /**
     * The quantity question every value-first flow opens with.
     *
     * @param  array<string, string>  $extra
     */
    private function askValue(User $user, Challenge $challenge, array $extra = []): void
    {
        try {
            $this->obligation($user, $challenge);
        } catch (CheckInRejectedException $refused) {
            $this->refuse($user, $refused, $challenge);

            return;
        }

        $this->openConversation($user, ConversationState::AwaitingCheckInValue, $challenge, $extra);
        $this->sendValuePrompt($user, $challenge);
    }

    private function sendValuePrompt(User $user, Challenge $challenge): void
    {
        $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.value_prompt', [
            'title' => $challenge->title,
            'unit' => (string) $challenge->unit_label,
        ]));
    }

    /**
     * The quantity report stashed in the conversation's payload, if any.
     */
    private function reportedValueOf(BotConversation $conversation): ?string
    {
        $value = $conversation->answer('reported_value');

        return is_string($value) && $value !== '' ? $value : null;
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
     * Say a check-in landed, with what it earned: the number, the score and
     * the streak on a quantity challenge, the streak alone on a binary one.
     */
    private function confirm(User $user, Challenge $challenge, CheckIn $checkIn): void
    {
        [$line, $replace] = $this->confirmations->line($challenge, $checkIn);

        $this->messenger->send($user, $this->messenger->line($user, $line, $replace));
    }

    /**
     * Hand a submitted proof to the creator, with the verdict buttons on it.
     *
     * The proof itself rides along: a photo, a voice note or a video goes out
     * as the media message, with the approve/reject buttons on its caption, so
     * the creator decides from the thing rather than from a description of it.
     * One send, not two — the messenger allows roughly a message a second per
     * chat, and the second send is the one that gets refused.
     *
     * When the media cannot go out — no file stored, an extension the upload
     * pipeline does not write, the file gone from disk, or the platform
     * refusing the upload — the creator still gets the sentence and the
     * buttons, because a creator who can approve from a text message is
     * strictly better served than one who hears nothing. The admin review queue
     * remains the backstop either way.
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

        $lines = [
            $this->messenger->line($creator, "bot.checkin.review_prompt_{$kind}", [
                'name' => $participant->first_name ?? $participant->name,
                'title' => $challenge->title,
            ]),
        ];

        $keyboard = [
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

        if (! $this->sendProofMedia($creator, $checkIn, $lines, $keyboard)) {
            $this->messenger->paragraphs($creator, $lines, $keyboard);
        }
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
