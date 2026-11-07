<?php

namespace App\Services\Telegram;

use App\Actions\CheckIns\AdvanceCheckInStep;
use App\Actions\CheckIns\StartCheckInSession;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\CheckInSessionStatus;
use App\Enums\ConversationState;
use App\Enums\SessionRejection;
use App\Enums\SettingKey;
use App\Enums\StepInputType;
use App\Exceptions\SessionRejectedException;
use App\Models\BotConversation;
use App\Models\Challenge;
use App\Models\ChallengeStep;
use App\Models\CheckIn;
use App\Models\CheckInSession;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\Callbacks\SessionStepCallback;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The timed session as the participant sees it: start, step by step, done.
 *
 * A sibling of `CheckInFlow` for the challenges whose check-in is a *session*
 * rather than a submission. The same split holds: every rule — which session,
 * which step, whether the wait has elapsed, whether the voice message fits its
 * cap — lives in the Task 2 actions, never here. What this class owns is the
 * surface: the step instructions, the "N seconds left" answer to an early tap
 * or message, and the completion confirmation, which is the settled-check-in
 * line verbatim because a completed session *is* a settled check-in.
 *
 * **No `BotConversation` is opened for a session, deliberately** — with one
 * exception. The session row is the state — one `in_progress` row per
 * (participant, period) is already guaranteed — and a conversation row would
 * be a second copy of that state that could go stale against it. The cost is
 * that a photo or voice message arrives with no conversation to key on, so
 * `MessageHandler` asks this flow directly after the conversation router
 * declines, and the flow answers only when an open session's current step is
 * waiting for exactly that kind of message. A conversation still wins over a
 * session when both are live: an explicit "send me the phrase/photo" prompt
 * outranks an ambient session the user may have forgotten about.
 *
 * The exception is a quantity challenge's final step: completing it settles
 * the period, and the settlement needs the number before it runs. The step's
 * evidence is stashed in a short-lived `AwaitingCheckInValue` conversation and
 * the step itself is answered only when the number arrives — the session row
 * keeps its state, the conversation holds nothing but the pending question.
 */
class SessionStepFlow
{
    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly StartCheckInSession $startSession,
        private readonly AdvanceCheckInStep $advanceStep,
        private readonly TelegramFileDownloader $files,
        private readonly BotMessenger $messenger,
        private readonly BotButtons $buttons,
        private readonly Settings $settings,
        private readonly ReportedValue $values,
        private readonly CheckInConfirmation $confirmations,
    ) {}

    /**
     * A tap on a timed challenge's check-in button: open (or rejoin) the session.
     */
    public function begin(User $user, Challenge $challenge): void
    {
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        $participant = $challenge->participants()->where('user_id', $user->getKey())->first();

        if ($participant === null) {
            $this->messenger->send($user, $this->messenger->line(
                $user,
                'bot.session.refused.not_a_participant',
                ['title' => $challenge->title],
            ));

            return;
        }

        $period = $challenge->periods()->containing(CarbonImmutable::now())->first();

        if ($period === null) {
            $this->messenger->send($user, $this->messenger->line(
                $user,
                'bot.session.refused.no_open_period',
                ['title' => $challenge->title],
            ));

            return;
        }

        try {
            $session = $this->startSession->handle($participant, $period);
        } catch (SessionRejectedException $refused) {
            $this->refuse($user, $refused, $challenge);

            return;
        }

        $this->showStep($user, $session);
    }

    /**
     * A tap on a session step's Next button.
     */
    public function tap(User $user, Challenge $challenge, int $stepOrder): void
    {
        $session = $this->openSessionOn($user, $challenge);

        if ($session === null) {
            $this->stale($user);

            return;
        }

        $step = $challenge->steps()->where('step_order', $stepOrder)->first();

        if ($step === null) {
            $this->stale($user);

            return;
        }

        if ($this->isFinalQuantityStep($challenge, $step)) {
            // Completing this step settles the period, and the settlement
            // scores a number — so the number is asked before the step is
            // answered, and the answer is held until it arrives.
            $this->awaitValue($user, $challenge, $step, []);

            return;
        }

        try {
            $session = $this->advanceStep->handle($session, $step);
        } catch (SessionRejectedException $refused) {
            $this->refuse($user, $refused, $challenge);

            return;
        }

        $this->settledOrNext($user, $challenge, $session);
    }

    /**
     * A photo, voice or video message that may belong to an open session.
     *
     * Returns whether it did, so the caller knows the message was answered.
     * Only the message kinds a step can actually want are ever claimed — a
     * text message is never a step's answer, because a text step does not
     * exist.
     */
    public function receiveMedia(User $user, TelegramUpdate $update): bool
    {
        $photo = $update->value('message.photo');
        $voice = $update->value('message.voice');
        $video = $update->value('message.video');

        $wants = match (true) {
            is_array($photo) && $photo !== [] => StepInputType::Image,
            is_array($voice) && isset($voice['file_id']) => StepInputType::Voice,
            is_array($video) && isset($video['file_id']) => StepInputType::Video,
            default => null,
        };

        if ($wants === null) {
            return false;
        }

        $session = $this->openSessions($user)
            ->first(fn (CheckInSession $session): bool => $this->currentStep($session)?->input_type === $wants);

        if ($session === null) {
            return false;
        }

        $challenge = $session->participant->challenge;
        $step = $this->currentStep($session);

        if ($step === null) {
            return false;
        }

        try {
            $submission = match ($wants) {
                StepInputType::Image => [
                    /** @var array<array-key, mixed> $photo */
                    'proof_path' => $this->files->downloadPhoto($user->platform, $photo),
                ],
                StepInputType::Voice => [
                    /** @var array<array-key, mixed> $voice */
                    'proof_path' => $this->files->downloadVoice($user->platform, $voice),
                    'voice_seconds' => (int) ($voice['duration'] ?? 0),
                ],
                default => [
                    /** @var array<array-key, mixed> $video */
                    'proof_path' => $this->files->downloadVideo($user->platform, $video),
                    'video_seconds' => (int) ($video['duration'] ?? 0),
                ],
            };

            // The challenge's size cap spans voice and video alike whenever the
            // messenger stated the bytes; `AdvanceCheckInStep` refuses the
            // submission when they overrun.
            $payload = $wants === StepInputType::Image ? null : ($wants === StepInputType::Voice ? $voice : $video);

            if (is_array($payload) && isset($payload['file_size']) && (is_int($payload['file_size']) || is_string($payload['file_size']) && ctype_digit((string) $payload['file_size']))) {
                $submission['media_size_kb'] = intdiv((int) $payload['file_size'], 1024);
            }
        } catch (Throwable $failure) {
            // Telegram or storage trouble is ours, not theirs, and the session
            // stays open: their next attempt should not be met with silence.
            Log::error('A session step submission could not be stored.', [
                'user_id' => $user->getKey(),
                'session_id' => $session->getKey(),
                'step' => $step->step_order,
                'reason' => $failure->getMessage(),
            ]);

            $this->messenger->send($user, $this->messenger->line($user, 'bot.session.store_error'));

            return true;
        }

        if ($this->isFinalQuantityStep($challenge, $step)) {
            // The evidence is stored; the step is not answered yet. Completing
            // it would settle the period without the number the settlement
            // scores, so the media rides in the conversation and the step is
            // advanced when the number arrives.
            $this->awaitValue($user, $challenge, $step, $submission);

            return true;
        }

        try {
            $session = $this->advanceStep->handle($session, $step, $submission);
        } catch (SessionRejectedException $refused) {
            $this->refuse($user, $refused, $challenge);

            return true;
        }

        $this->settledOrNext($user, $challenge, $session);

        return true;
    }

    /**
     * The typed quantity report, answering the final step's value question.
     *
     * The stashed step and its evidence are replayed through the ordinary
     * `AdvanceCheckInStep` — every gate (current step, wait elapsed, media
     * shaped) re-checked there, exactly as a tap's answer would be, because
     * the question being typed rather than tapped changes nothing about the
     * rules.
     */
    public function receiveValue(User $user, BotConversation $conversation, ?string $text): void
    {
        $challenge = $this->challengeOf($conversation);

        if ($challenge === null) {
            $this->abandonValue($user, $conversation);

            return;
        }

        $value = $this->values->normalise($text);

        if ($value === null) {
            $conversation->forceFill(['expires_at' => now()->addMinutes($this->settings->integer(SettingKey::ConversationTtlMinutes))])->save();

            $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.value_error', [
                'unit' => (string) $challenge->unit_label,
            ]));

            return;
        }

        $session = $this->openSessionOn($user, $challenge);
        $stepOrder = $conversation->answer('step');
        $step = is_int($stepOrder) || is_string($stepOrder) && $stepOrder !== ''
            ? $challenge->steps()->where('step_order', (int) $stepOrder)->first()
            : null;

        if ($session === null || $step === null) {
            // The session closed or expired while the question was open — the
            // expiry sweep does not know about conversations.
            $this->abandonValue($user, $conversation);

            return;
        }

        $stashed = $conversation->answer('submission');

        try {
            $session = $this->advanceStep->handle($session, $step, is_array($stashed) ? $stashed : [], null, $value);
        } catch (SessionRejectedException $refused) {
            if ($refused->reason === SessionRejection::TooEarly) {
                // The wait is still running. The answer is good — the question
                // stays open so they can send the same number once it has
                // elapsed, rather than starting the step over.
                $this->refuse($user, $refused, $challenge);

                return;
            }

            $this->abandonValue($user, $conversation);
            $this->refuse($user, $refused, $challenge);

            return;
        }

        $conversation->delete();
        $this->settledOrNext($user, $challenge, $session);
    }

    /**
     * Whether this step is the one whose completion a quantity report must
     * precede: the last step of a quantity challenge.
     */
    private function isFinalQuantityStep(Challenge $challenge, ChallengeStep $step): bool
    {
        if (! $challenge->scoring_type->isQuantity()) {
            return false;
        }

        /** @var int|null $last */
        $last = $challenge->steps()->max('step_order');

        return $last !== null && $step->step_order === $last;
    }

    /**
     * Ask the quantity question, stashing the step and its evidence in the
     * conversation. The session row is untouched — its state still says this
     * step is current, which is exactly what `AdvanceCheckInStep` will insist
     * on when the answer arrives.
     *
     * @param  array{proof_path?: string|null, voice_seconds?: int|null, video_seconds?: int|null, media_size_kb?: int|null}  $submission
     */
    private function awaitValue(User $user, Challenge $challenge, ChallengeStep $step, array $submission): void
    {
        BotConversation::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'state' => ConversationState::AwaitingCheckInValue,
                'payload' => [
                    'challenge_id' => $challenge->getKey(),
                    // The marker that routes this conversation's answer here
                    // rather than to the check-in flow.
                    'session' => '1',
                    'step' => $step->step_order,
                    'submission' => $submission,
                ],
                'expires_at' => now()->addMinutes($this->settings->integer(SettingKey::ConversationTtlMinutes)),
            ],
        );

        $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.value_prompt', [
            'title' => $challenge->title,
            'unit' => (string) $challenge->unit_label,
        ]));
    }

    /**
     * Close the value question, saying the session is gone.
     */
    private function abandonValue(User $user, BotConversation $conversation): void
    {
        $conversation->delete();

        $this->stale($user);
    }

    /**
     * The session has moved on without the row in the chat that names it.
     *
     * One line and one button, for four sites that all mean the same thing: the
     * user still has a step in front of them that no longer matches the world, and
     * what they need is not an explanation but a way to see where the session
     * actually is. `/checkin` answers that for every challenge, so the button
     * names it.
     */
    private function stale(User $user): void
    {
        $this->buttons->send($user, 'bot.session.stale', 'checkin');
    }

    /**
     * The challenge this conversation's value question answers, or null.
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
     * Say what the session's current step wants, and when.
     */
    private function showStep(User $user, CheckInSession $session): void
    {
        $challenge = $session->participant->challenge;
        $step = $this->currentStep($session);

        if ($step === null) {
            // A completed or expired session has no current step; reaching here
            // with one open would be a wiring bug, so the honest answer is the
            // stale line rather than a crash.
            $this->stale($user);

            return;
        }

        $replace = [
            'title' => $challenge->title,
            'step' => $step->step_order,
            'total' => $challenge->steps()->count(),
            'label' => $step->label,
            'wait' => CompactDuration::format($step->min_wait_seconds),
            // A voice step carries its own cap; a video step's lives on the
            // challenge, alongside the size cap the whole flow shares.
            'max' => match ($step->input_type) {
                StepInputType::Video => $challenge->proof_media_max_seconds,
                default => $step->voice_max_seconds,
            },
        ];

        $keyboard = match ($step->input_type) {
            StepInputType::Button => [[[
                'text' => $this->messenger->line($user, 'bot.session.next_button'),
                'callback_data' => BotCallback::encode(SessionStepCallback::ACTION, $challenge->join_token, (string) $step->step_order),
            ]]],
            default => null,
        };

        $this->messenger->paragraphs($user, [
            $step->label === null ? null : $step->label,
            $this->messenger->line($user, "bot.session.step_{$step->input_type->value}", $replace),
        ], $keyboard);
    }

    /**
     * A completed session with an approved check-in is a settled check-in,
     * so the confirmation is the settled-check-in line; a completed session
     * still waiting on a verdict (AI review or the manual queue, Phase 14
     * Task 3) says so instead of claiming success; anything else shows the
     * next step.
     */
    private function settledOrNext(User $user, Challenge $challenge, CheckInSession $session): void
    {
        if ($session->status === CheckInSessionStatus::Completed) {
            $checkIn = CheckIn::query()
                ->where('challenge_participant_id', $session->participant->getKey())
                ->where('challenge_period_id', $session->period->getKey())
                ->first();

            if ($checkIn !== null && ! $checkIn->status->isSettled()) {
                $this->messenger->send($user, $this->messenger->line(
                    $user,
                    'bot.session.submitted_for_review',
                    ['title' => $challenge->title],
                ));

                return;
            }

            if ($checkIn !== null) {
                // The scored sentence on a quantity challenge, the plain one
                // on a binary challenge — the same words a tap confirms with.
                [$line, $replace] = $this->confirmations->line($challenge, $checkIn);
                $this->messenger->send($user, $this->messenger->line($user, $line, $replace));

                return;
            }

            $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.confirmed', [
                'title' => $challenge->title,
                'streak' => $session->participant->refresh()->current_streak,
            ]));

            return;
        }

        $this->showStep($user, $session->fresh() ?? $session);
    }

    /**
     * Turn a refusal into the sentence its reason names.
     *
     * Every reason carries its own numbers where numbers are the message —
     * the seconds left on an early answer, the cap a voice message blew past —
     * which is why they travel on the exception rather than being recomputed.
     */
    private function refuse(User $user, SessionRejectedException $refused, Challenge $challenge): void
    {
        $line = match ($refused->reason) {
            SessionRejection::TooEarly => $this->messenger->line($user, 'bot.session.too_early', [
                'seconds' => $refused->remainingSeconds,
            ]),
            SessionRejection::VoiceTooLong => $this->messenger->line($user, 'bot.session.voice_too_long', [
                'seconds' => $refused->voiceSeconds,
                'max' => $refused->voiceCeiling,
            ]),
            // The video pair reuses the voice counters on the exception: they
            // carry "how long it ran" and "how long it may run", which is the
            // whole message either way.
            SessionRejection::VideoTooLong => $this->messenger->line($user, 'bot.session.video_too_long', [
                'seconds' => $refused->voiceSeconds,
                'max' => $refused->voiceCeiling,
            ]),
            // Null rather than a sentence: "the session moved on" is the one
            // refusal answered with a button, because the reason it confuses is
            // that the user cannot see where they are.
            SessionRejection::WrongStep, SessionRejection::SessionNotOpen => null,
            default => $this->messenger->line(
                $user,
                "bot.session.refused.{$refused->reason->value}",
                ['title' => $challenge->title],
            ),
        };

        if ($line === null) {
            $this->stale($user);

            return;
        }

        $this->messenger->send($user, $line);
    }

    /**
     * The user's one open session on this challenge, or null.
     */
    private function openSessionOn(User $user, Challenge $challenge): ?CheckInSession
    {
        return $this->openSessions($user)
            ->first(fn (CheckInSession $session): bool => $session->participant->challenge_id === $challenge->getKey());
    }

    /**
     * Every open session of this user's, newest first.
     *
     * @return Collection<int, CheckInSession>
     */
    private function openSessions(User $user): Collection
    {
        return CheckInSession::query()
            ->where('status', CheckInSessionStatus::InProgress)
            ->whereHas('participant', fn ($query) => $query->where('user_id', $user->getKey()))
            ->with('participant.challenge')
            ->orderByDesc('started_at')
            ->get();
    }

    /**
     * The step the session is sitting on, or null when it sits on none.
     */
    private function currentStep(CheckInSession $session): ?ChallengeStep
    {
        if ($session->current_step_order === null) {
            return null;
        }

        return $session->participant->challenge
            ->steps()
            ->where('step_order', $session->current_step_order)
            ->first();
    }
}
