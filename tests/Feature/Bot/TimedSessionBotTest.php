<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Enums\CheckInSessionStatus;
use App\Enums\CheckInStatus;
use App\Enums\ConversationState;
use App\Enums\FlowType;
use App\Enums\ProofType;
use App\Enums\ScoringType;
use App\Enums\SettingKey;
use App\Enums\StepInputType;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\BotConversation;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengeStep;
use App\Models\CheckIn;
use App\Models\CheckInSession;
use App\Models\Entitlement;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\Callbacks\CheckInCallback;
use App\Services\Telegram\Callbacks\SessionStepCallback;
use App\Services\Telegram\CompactDuration;
use App\Services\Telegram\Wizards\CreateChallengeWizard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * The timed session end to end: the wizard gathers a step design, a participant
 * runs the session the design prescribes, and a photo or voice lands in the
 * session even though a session owns no conversation of its own. Everything
 * goes through `ProcessTelegramUpdate`, so what is under test is the surface —
 * the ordering gates themselves belong to the Task 2 actions, covered next
 * door.
 *
 * The telegram ids share a namespace (888_2xx): the creator, the participant
 * and the stranger are distinguishable at a glance.
 */

const SESSION_TELEGRAM_ID = 888_200_1;

const STRANGER_TELEGRAM_ID = 888_200_9;

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.bot_username' => 'ChallengesBot',
    ]);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');

    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'member']]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
        '*getFile*' => Http::response(['ok' => true, 'result' => ['file_id' => 'x', 'file_path' => 'voice/step.ogg']]),
        'https://api.telegram.org/file/*' => Http::response('ogg-bytes'),
    ]);
});

describe('the step loop', function () {
    beforeEach(function () {
        User::factory()
            ->telegram(SESSION_TELEGRAM_ID)
            ->channelVerified()
            ->preferring('en')
            ->create(['first_name' => 'Sara']);

        Entitlement::factory()->createSlot()->create([
            'user_id' => User::query()->where('platform_user_id', SESSION_TELEGRAM_ID)->sole()->getKey(),
        ]);
    });

    it('gathers a design through the loop and confirms the duration it implies', function () {
        designerTypesTheSteps();

        expect(latestBotMessage(21)['text'])
            ->toContain(botCopy('bot.wizard.summary_steps', [
                'steps' => 2,
                'minimum' => CompactDuration::format(180),
                'period' => CompactDuration::format(86_400),
            ]))
            ->and(liveFlowState())->toBe(ConversationState::AwaitingCreateConfirmation->value);
    });

    it('refuses a design whose waits outlast the period', function () {
        sessionTypes(SESSION_TELEGRAM_ID, '/create');
        sessionTypes(SESSION_TELEGRAM_ID, 'Evening stretch');
        sessionTypes(SESSION_TELEGRAM_ID, 'Ten minutes, then sleep.');
        sessionChooses('daily');
        sessionChooses('Asia/Tehran');
        sessionChooses(CreateChallengeWizard::TOMORROW);
        sessionTypes(SESSION_TELEGRAM_ID, '5');
        sessionChooses(ScoringType::Binary->value);
        sessionChooses(ProofType::Button->value);
        sessionChooses('invite_only');
        sessionChooses(FlowType::TimedSession->value);

        sessionChooses(CreateChallengeWizard::ADD_STEP);
        sessionChooses(StepInputType::Button->value);
        sessionTypes(SESSION_TELEGRAM_ID, '86400');
        sessionTypes(SESSION_TELEGRAM_ID, 'The whole day');
        sessionChooses(CreateChallengeWizard::ADD_STEP);
        sessionChooses(StepInputType::Button->value);
        sessionTypes(SESSION_TELEGRAM_ID, '60');
        sessionTypes(SESSION_TELEGRAM_ID, 'And a minute');
        sessionChooses(CreateChallengeWizard::DONE_STEPS);

        // The loop answers the refusal and stays put — nothing was created and
        // the design is still editable.
        expect(latestBotMessage(20)['text'])
            ->toContain(botCopy('bot.wizard.steps_too_long', [
                'minimum' => CompactDuration::format(86_460),
                'period' => CompactDuration::format(86_400),
            ]))
            ->and(liveFlowState())->toBe(ConversationState::AwaitingStepLoop->value)
            ->and(Challenge::query()->count())->toBe(0);
    });

    it('refuses Done before a single step has been designed', function () {
        BotConversation::factory()
            ->at(ConversationState::AwaitingFlowType, [
                'title' => 'Evening stretch',
                'period_type' => 'daily',
                'timezone' => 'Asia/Tehran',
                'start_date' => CarbonImmutable::now('Asia/Tehran')->addDay()->toDateString(),
                'total_periods' => 5,
                'proof_type' => 'button',
                'visibility' => 'invite_only',
            ])
            ->create(['user_id' => User::query()->where('platform_user_id', SESSION_TELEGRAM_ID)->sole()->getKey()]);

        sessionChooses(FlowType::TimedSession->value);
        sessionChooses(CreateChallengeWizard::DONE_STEPS);

        expect(latestBotMessage(2)['text'])->toContain(botCopy('bot.wizard.awaiting_step_loop.error'))
            ->and(liveFlowState())->toBe(ConversationState::AwaitingStepLoop->value);
    });

    it('creates the challenge carrying the steps the loop gathered', function () {
        designerTypesTheSteps();

        sessionChooses(CreateChallengeWizard::CONFIRM);

        $challenge = Challenge::query()->sole();

        expect($challenge->flow_type)->toBe(FlowType::TimedSession)
            ->and($challenge->steps()->orderBy('step_order')->get()->map(fn (ChallengeStep $step): array => [
                'order' => $step->step_order,
                'input' => $step->input_type,
                'wait' => $step->min_wait_seconds,
                'label' => $step->label,
            ])->all())->toBe([
                ['order' => 1, 'input' => StepInputType::Button, 'wait' => 60, 'label' => 'Stretch'],
                ['order' => 2, 'input' => StepInputType::Voice, 'wait' => 120, 'label' => null],
            ]);
    });
});

describe('a participant running a session', function () {
    beforeEach(function () {
        $this->challenge = runningTimedChallenge();
        [$participantUser, $participant] = theParticipantIn($this->challenge);
        $this->participant = $participant;
    });

    it('starts on the check-in tap and shows the first step with its Next button', function () {
        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), SESSION_TELEGRAM_ID);

        $session = theSessionOf($this->participant);

        expect($session->status)->toBe(CheckInSessionStatus::InProgress)
            ->and($session->current_step_order)->toBe(1)
            ->and(soleBotMessage()['text'])
            ->toContain(botCopy('bot.session.step_button', [
                'title' => 'Evening stretch',
                'step' => 1,
                'total' => 2,
                'wait' => CompactDuration::format(60),
            ]))
            ->and(botKeyboard())->toBe([[[
                'text' => botCopy('bot.session.next_button'),
                'callback_data' => BotCallback::encode(SessionStepCallback::ACTION, $this->challenge->join_token, '1'),
            ]]]);
    });

    it('refuses to start for somebody outside the challenge', function () {
        User::factory()->telegram(STRANGER_TELEGRAM_ID)->preferring('en')->create(['channel_verified_at' => now()]);

        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), STRANGER_TELEGRAM_ID);

        expect(lastBotReply()['text'])
            ->toBe(botCopy('bot.session.refused.not_a_participant', ['title' => 'Evening stretch']))
            ->and(CheckInSession::query()->count())->toBe(0);
    });

    it('answers an early tap with the seconds that remain', function () {
        // A whole second, so `started_at` survives the database's second
        // precision untouched and the remaining-seconds count is exact.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfMinute());

        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));

        sessionTaps(BotCallback::encode(SessionStepCallback::ACTION, $this->challenge->join_token, '1'), SESSION_TELEGRAM_ID);

        expect(lastBotReply()['text'])->toBe(botCopy('bot.session.too_early', ['seconds' => 30]))
            ->and(theSessionOf($this->participant)->current_step_order)->toBe(1);

        CarbonImmutable::setTestNow();
    });

    it('walks the design to a settled check-in', function () {
        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(3));

        // Step 1: the button.
        sessionTaps(BotCallback::encode(SessionStepCallback::ACTION, $this->challenge->join_token, '1'), SESSION_TELEGRAM_ID);

        expect(theSessionOf($this->participant)->current_step_order)->toBe(2)
            ->and(latestBotMessage(2)['text'])->toContain(botCopy('bot.session.step_voice', [
                'title' => 'Evening stretch',
                'step' => 2,
                'total' => 2,
                'wait' => CompactDuration::format(120),
                'max' => 30,
            ]));

        // Step 2: the voice message, in under the cap.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        sessionSendsVoice(SESSION_TELEGRAM_ID, 20);

        $session = theSessionOf($this->participant);

        expect($session->status)->toBe(CheckInSessionStatus::Completed)
            ->and($session->current_step_order)->toBeNull()
            ->and($this->participant->refresh()->current_streak)->toBe(1)
            ->and(CheckIn::query()->where('challenge_participant_id', $this->participant->getKey())->sole()->status)
            ->toBe(CheckInStatus::Approved)
            ->and(lastBotReply()['text'])
            ->toBe(botCopy('bot.checkin.confirmed', ['title' => 'Evening stretch', 'streak' => 1]));

        CarbonImmutable::setTestNow();
    });

    it('refuses a voice message past the step’s cap and keeps the session open', function () {
        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(3));

        sessionTaps(BotCallback::encode(SessionStepCallback::ACTION, $this->challenge->join_token, '1'), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        sessionSendsVoice(SESSION_TELEGRAM_ID, 60);

        expect(lastBotReply()['text'])->toBe(botCopy('bot.session.voice_too_long', ['seconds' => 60, 'max' => 30]))
            ->and(theSessionOf($this->participant))
            ->status->toBe(CheckInSessionStatus::InProgress)
            ->current_step_order->toBe(2);

        CarbonImmutable::setTestNow();
    });

    it('refuses the wrong step as stale, however the step is named', function () {
        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(3));

        // The button for step 2, while step 1 is the one waiting.
        sessionTaps(BotCallback::encode(SessionStepCallback::ACTION, $this->challenge->join_token, '2'), SESSION_TELEGRAM_ID);

        expect(lastBotReply()['text'])->toBe(botCopy('bot.session.stale'))
            // A stale step is the one refusal whose reason is invisible — the user
            // cannot see where the flow is, only where they thought it was — so it
            // is answered with the command that shows them, not with a sentence.
            ->and(lastBotKeyboard())->toBe([[commandButton('en', 'checkin')]])
            ->and(theSessionOf($this->participant)->current_step_order)->toBe(1);

        CarbonImmutable::setTestNow();
    });

    it('refuses to start a second session on a period a session already settled', function () {
        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));

        sessionTaps(BotCallback::encode(SessionStepCallback::ACTION, $this->challenge->join_token, '1'), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        sessionSendsVoice(SESSION_TELEGRAM_ID, 20);

        CarbonImmutable::setTestNow();

        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), SESSION_TELEGRAM_ID);

        expect(lastBotReply()['text'])->toBe(botCopy('bot.session.refused.already_settled'));

        CarbonImmutable::setTestNow();
    });
});

describe('a photo, voice or video with nowhere conversation-shaped to land', function () {
    it('answers an open session waiting for that kind of message', function () {
        $challenge = runningTimedChallenge();
        [$user, $participant] = theParticipantIn($challenge);

        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(3));

        sessionTaps(BotCallback::encode(SessionStepCallback::ACTION, $challenge->join_token, '1'), SESSION_TELEGRAM_ID);

        // Now on the voice step, with no conversation anywhere. A voice message
        // must find the session on its own.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        sessionSendsVoice(SESSION_TELEGRAM_ID, 20);

        expect(theSessionOf($participant)->status)->toBe(CheckInSessionStatus::Completed);

        CarbonImmutable::setTestNow();
    });

    it('answers the check-in conversation when one is open, session or not', function () {
        $challenge = runningTimedChallenge();
        [$user, $participant] = theParticipantIn($challenge);

        // The same user also owes a photo-approval check-in in another
        // challenge, and its conversation is live. The photo must land there —
        // an explicit prompt outranks an ambient session.
        $photoChallenge = Challenge::factory()
            ->active()
            ->provenBy(ProofType::ImageApproval)
            ->create(['join_token' => 'phototoken', 'title' => 'Morning run', 'total_periods' => 10]);
        app(MaterialiseChallengePeriods::class)->handle($photoChallenge);
        $photoParticipant = ChallengeParticipant::factory()->for($photoChallenge)->for($user)->create();

        // An open image session, sitting on the voice step: the session the
        // photo would have claimed had the conversation not won.
        $session = CheckInSession::factory()
            ->onStep(2)
            ->startedAt(now()->subMinutes(10))
            ->create([
                'challenge_participant_id' => $participant->getKey(),
                'challenge_period_id' => $challenge->periods()->containing(now())->first()->getKey(),
            ]);

        $checkIn = CheckIn::factory()
            ->on($photoParticipant, $photoChallenge->periods()->containing(now())->first())
            ->create();

        BotConversation::factory()
            ->at(ConversationState::AwaitingCheckInPhoto, ['challenge_id' => $photoChallenge->getKey()])
            ->create(['user_id' => $user->getKey()]);

        Http::fake([
            '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
            '*getFile*' => Http::response(['ok' => true, 'result' => ['file_id' => 'x', 'file_path' => 'photos/proof.jpg']]),
            'https://api.telegram.org/file/*' => Http::response('jpeg-bytes'),
        ]);

        $update = TelegramUpdate::factory()
            ->photoFrom(['id' => SESSION_TELEGRAM_ID, 'first_name' => 'Sara'], 'AgACphoto-x')
            ->create();

        dispatch_sync(new ProcessTelegramUpdate($update));

        expect($checkIn->refresh()->status)->toBe(CheckInStatus::Submitted)
            ->and($session->refresh()->current_step_order)->toBe(2)
            ->and($session->submissions()->count())->toBe(0);
    });

    it('answers an open session waiting for a video the same way', function () {
        // The mirror run with a video step: the video's caps live on the
        // challenge, so they travel with it here.
        $challenge = Challenge::factory()
            ->active()
            ->timedSession()
            ->provenBy(ProofType::Button)
            ->create([
                'join_token' => 'sessiontoken',
                'title' => 'Evening stretch',
                'total_periods' => 5,
                'proof_media_max_seconds' => 120,
                'proof_media_max_size_kb' => 4096,
            ]);

        app(MaterialiseChallengePeriods::class)->handle($challenge);

        ChallengeStep::factory()->for($challenge)->atOrder(1)->waiting(60)->create(['label' => 'Start']);
        ChallengeStep::factory()->for($challenge)->atOrder(2)->video()->waiting(120)->create(['label' => null]);

        [, $participant] = theParticipantIn($challenge->fresh());

        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(3));

        sessionTaps(BotCallback::encode(SessionStepCallback::ACTION, $challenge->join_token, '1'), SESSION_TELEGRAM_ID);

        expect(latestBotMessage(2)['text'])->toContain(botCopy('bot.session.step_video', [
            'title' => 'Evening stretch',
            'step' => 2,
            'total' => 2,
            'wait' => CompactDuration::format(120),
            'max' => 120,
        ]));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        sessionSendsVideo(SESSION_TELEGRAM_ID, 45, file_size: 2_000_000);

        // The video found the session with no conversation's help, its duration
        // and size were checked against the challenge's caps, and the last step
        // settling completes the session into an approved check-in.
        expect(theSessionOf($participant)->status)->toBe(CheckInSessionStatus::Completed)
            ->and(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->status)
            ->toBe(CheckInStatus::Approved);

        CarbonImmutable::setTestNow();
    });

    it('leaves a message with no session and no conversation to the fallback', function () {
        User::factory()->telegram(SESSION_TELEGRAM_ID)->preferring('en')->create(['channel_verified_at' => now()]);

        sessionSendsVoice(SESSION_TELEGRAM_ID, 20);

        expect(lastBotReply()['text'])->toBe(botCopy('bot.fallback.unknown'));
    });
});

/*
 * Everything below is arrangement, not assertion.
 */

/**
 * A typed message, through the whole inbound path.
 */
function sessionTypes(int $telegramId, string $text): void
{
    $update = TelegramUpdate::factory()
        ->messageFrom(['id' => $telegramId, 'first_name' => 'Sara'], $text)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * A tap, through the whole inbound path.
 */
function sessionTaps(string $data, int $telegramId): void
{
    $update = TelegramUpdate::factory()
        ->callbackQueryFrom(['id' => $telegramId, 'first_name' => 'Sara'], $data)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * A voice message, through the whole inbound path.
 */
function sessionSendsVoice(int $telegramId, int $duration, string $fileId = 'AwSessionVoice'): void
{
    $update = TelegramUpdate::factory()
        ->voiceFrom(['id' => $telegramId, 'first_name' => 'Sara'], $duration, $fileId)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * A video message, through the whole inbound path.
 */
function sessionSendsVideo(int $telegramId, int $duration, string $fileId = 'BaSessionVideo', ?int $file_size = null): void
{
    $update = TelegramUpdate::factory()
        ->videoFrom(['id' => $telegramId, 'first_name' => 'Sara'], $duration, $fileId, $file_size)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * Tap the button the wizard last offered for `$value`.
 *
 * Reads the state out of the live conversation rather than being told it, so
 * the walk does not restate the order the wizard owns.
 */
function sessionChooses(string $value): void
{
    $state = BotConversation::query()->sole()->state;

    sessionTaps(BotCallback::encode(CreateChallengeWizard::ACTION, $state->value, $value), SESSION_TELEGRAM_ID);
}

/**
 * The wizard's step loop, walked through in full.
 *
 * One button step and one voice step, both quick: the run that follows can
 * travel a few minutes and satisfy every gate without leaving the period.
 */
function designerTypesTheSteps(): void
{
    sessionTypes(SESSION_TELEGRAM_ID, '/create');
    sessionTypes(SESSION_TELEGRAM_ID, 'Evening stretch');
    sessionTypes(SESSION_TELEGRAM_ID, 'Ten minutes, then sleep.');
    sessionChooses('daily');
    sessionChooses('Asia/Tehran');
    sessionChooses(CreateChallengeWizard::TOMORROW);
    sessionTypes(SESSION_TELEGRAM_ID, '5');
    sessionChooses(ScoringType::Binary->value);
    sessionChooses(ProofType::Button->value);
    sessionChooses('invite_only');
    sessionChooses(FlowType::TimedSession->value);

    // Step 1: a labelled button step.
    sessionChooses(CreateChallengeWizard::ADD_STEP);
    sessionChooses(StepInputType::Button->value);
    sessionTypes(SESSION_TELEGRAM_ID, '60');
    sessionTypes(SESSION_TELEGRAM_ID, 'Stretch');

    // Step 2: a voice step, capped at 30 seconds, left unnamed.
    sessionChooses(CreateChallengeWizard::ADD_STEP);
    sessionChooses(StepInputType::Voice->value);
    sessionTypes(SESSION_TELEGRAM_ID, '120');
    sessionTypes(SESSION_TELEGRAM_ID, '30');
    sessionChooses(CreateChallengeWizard::SKIP);

    sessionChooses(CreateChallengeWizard::DONE_STEPS);
}

/**
 * The conversation's current state, or null once the flow has closed.
 */
function liveFlowState(): ?string
{
    $conversation = BotConversation::query()->first();

    return $conversation?->state->value;
}

/**
 * A running timed challenge: one button step then one voice step.
 *
 * The design is minimal — a minute of waiting, then a voice capped at half a
 * minute — so a participant can run it end to end inside a few travelled
 * minutes.
 */
function runningTimedChallenge(): Challenge
{
    $challenge = Challenge::factory()
        ->active()
        ->timedSession()
        ->provenBy(ProofType::Button)
        ->create(['join_token' => 'sessiontoken', 'title' => 'Evening stretch', 'total_periods' => 5]);

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    ChallengeStep::factory()->for($challenge)->atOrder(1)->waiting(60)->create(['label' => 'Start']);
    ChallengeStep::factory()->for($challenge)->atOrder(2)->voice(30)->waiting(120)->create(['label' => null]);

    return $challenge->fresh();
}

/**
 * The challenge's creator and a second participant, both gate-verified and
 * English-preferring. The participant is the one who runs the sessions.
 *
 * @return array{0: User, 1: ChallengeParticipant}
 */
function theParticipantIn(Challenge $challenge): array
{
    $creator = User::factory()->telegram(888_200_2)->preferring('en')->create(['channel_verified_at' => now()]);
    $challenge->forceFill(['creator_id' => $creator->getKey()])->save();

    $user = User::factory()->telegram(SESSION_TELEGRAM_ID)->preferring('en')->create(['channel_verified_at' => now()]);
    $participant = ChallengeParticipant::factory()->for($challenge)->for($user)->create();

    return [$user, $participant];
}

/**
 * The participant's one session.
 */
function theSessionOf(ChallengeParticipant $participant): CheckInSession
{
    return CheckInSession::query()->where('challenge_participant_id', $participant->getKey())->sole();
}

/*
 * A quantity timed session: the design's steps run exactly as before, and one
 * question is added — "how many?" — asked once, at the final step, after its
 * evidence is in. Intermediate steps never ask; the session row keeps its state
 * while a short-lived conversation carries the number.
 */
describe('a quantity timed session', function () {
    beforeEach(function () {
        $this->challenge = Challenge::factory()
            ->active()
            ->timedSession()
            ->provenBy(ProofType::Button)
            ->quantity()
            ->create(['join_token' => 'scoretoken', 'title' => 'Evening stretch', 'total_periods' => 5]);

        app(MaterialiseChallengePeriods::class)->handle($this->challenge);

        ChallengeStep::factory()->for($this->challenge)->atOrder(1)->waiting(60)->create(['label' => 'Start']);
        ChallengeStep::factory()->for($this->challenge)->atOrder(2)->voice(30)->waiting(120)->create(['label' => null]);

        $this->challenge = $this->challenge->fresh();

        [, $participant] = theParticipantIn($this->challenge);
        $this->participant = $participant;
    });

    it('asks for the value at the final step only, and settles with the score', function () {
        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(3));

        // The intermediate step: answered, advanced, and no value question —
        // the number belongs to the period, not to every step of it.
        sessionTaps(BotCallback::encode(SessionStepCallback::ACTION, $this->challenge->join_token, '1'), SESSION_TELEGRAM_ID);

        expect(theSessionOf($this->participant)->current_step_order)->toBe(2)
            ->and(collect(botMessages())->map(fn (array $message): string => $message['text'])->implode("\n"))
            ->not->toContain(__('bot.checkin.value_prompt', [
                'title' => 'Evening stretch', 'unit' => 'pushups',
            ]));

        // The final step's evidence arrives, and only then is the number asked.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        sessionSendsVoice(SESSION_TELEGRAM_ID, 20);

        $session = theSessionOf($this->participant);

        expect($session->status)->toBe(CheckInSessionStatus::InProgress)
            ->and($session->current_step_order)->toBe(2)
            ->and(BotConversation::query()->sole()->state)->toBe(ConversationState::AwaitingCheckInValue)
            ->and(lastBotReply()['text'])->toBe(__('bot.checkin.value_prompt', [
                'title' => 'Evening stretch', 'unit' => 'pushups',
            ]));

        sessionTypes(SESSION_TELEGRAM_ID, '45');

        $checkIn = CheckIn::query()->where('challenge_participant_id', $this->participant->getKey())->sole();

        expect(theSessionOf($this->participant)->status)->toBe(CheckInSessionStatus::Completed)
            ->and($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->reported_value)->toBe('45.00')
            ->and($checkIn->score)->toBe('150.00')
            ->and($this->participant->refresh()->total_score)->toBe('150.00')
            ->and(BotConversation::query()->exists())->toBeFalse()
            ->and(lastBotReply()['text'])->toBe(__('bot.checkin.confirmed_scored', [
                'title' => 'Evening stretch', 'value' => '45', 'unit' => 'pushups', 'score' => 150, 'streak' => 1,
            ]));

        CarbonImmutable::setTestNow();
    });

    it('keeps the question open when the answer is unreadable, then accepts the number', function () {
        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(3));
        sessionTaps(BotCallback::encode(SessionStepCallback::ACTION, $this->challenge->join_token, '1'), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));
        sessionSendsVoice(SESSION_TELEGRAM_ID, 20);

        sessionTypes(SESSION_TELEGRAM_ID, 'some');

        expect(BotConversation::query()->sole()->state)->toBe(ConversationState::AwaitingCheckInValue)
            ->and(theSessionOf($this->participant)->status)->toBe(CheckInSessionStatus::InProgress)
            // The obligation row the session opened is still untouched: no
            // number, no verdict, nothing settled on a guess.
            ->and(CheckIn::query()->sole()->status)->toBe(CheckInStatus::Pending);

        sessionTypes(SESSION_TELEGRAM_ID, '45');

        expect(CheckIn::query()->where('challenge_participant_id', $this->participant->getKey())->sole()->score)
            ->toBe('150.00');

        CarbonImmutable::setTestNow();
    });

    it('settles below-target through the ordinary bar when no opt-in was given', function () {
        // The factory's safer default: partial does not count. A report under
        // the target misses exactly as an unfinished session would.
        sessionTaps(BotCallback::encode(CheckInCallback::ACTION, $this->challenge->join_token), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(3));
        sessionTaps(BotCallback::encode(SessionStepCallback::ACTION, $this->challenge->join_token, '1'), SESSION_TELEGRAM_ID);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));
        sessionSendsVoice(SESSION_TELEGRAM_ID, 20);

        sessionTypes(SESSION_TELEGRAM_ID, '15');

        $checkIn = CheckIn::query()->where('challenge_participant_id', $this->participant->getKey())->sole();

        expect($checkIn->status)->toBe(CheckInStatus::Frozen)
            ->and($checkIn->reported_value)->toBe('15.00')
            ->and($checkIn->score)->toBeNull()
            ->and($this->participant->refresh()->current_streak)->toBe(0)
            ->and(lastBotReply()['text'])->toBe(__('bot.checkin.below_target_frozen', [
                'title' => 'Evening stretch', 'value' => '15', 'target' => '30', 'unit' => 'pushups', 'streak' => 0,
            ]));

        CarbonImmutable::setTestNow();
    });
});
