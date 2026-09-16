<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Enums\ChallengeStatus;
use App\Enums\CheckInStatus;
use App\Enums\ConversationState;
use App\Enums\FlowType;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\BotConversation;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\Callbacks\CheckInCallback;
use App\Services\Telegram\Callbacks\ReviewCheckInCallback;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * The check-in conversation end to end: `/checkin` lists what is owed, the proof
 * arrives for all three proof types, and a photo verdict travels back through the
 * creator's buttons. Every step goes through `ProcessTelegramUpdate`, so what is
 * under test is the surface — the rules themselves are owned by `SubmitCheckIn`
 * and `ReviewCheckIn`, already covered in the Domain suite.
 *
 * Users here arrive pre-verified (`channel_verified_at` already stamped), so the
 * gate's cache answers and `getChatMember` is only needed by the tests that are
 * specifically about the gate or the lapse of it.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.telegram.bot_token' => '123456:TEST-TOKEN',
        'services.telegram.bot_username' => 'ChallengesBot',
    ]);

    $this->settings = app(Settings::class);
    $this->settings->set(SettingKey::RequiredChannel, '@challenges');
});

/**
 * Telegram's answers: files fetched, membership confirmed when asked, taps
 * acknowledged, messages accepted.
 *
 * `$overrides` replaces a stub by pattern, so a test that needs one endpoint to
 * refuse does not have to restate the lot — `Http::fake()` appends and the
 * first matching pattern wins, so a second call would be shadowed by this one.
 *
 * @param  array<string, Response|ResponseSequence>  $overrides
 */
function telegramServesTheLot(array $overrides = []): void
{
    Http::fake($overrides + [
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'member']]),
        '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
        // The creator's proof notifications are media sends, and a multipart
        // upload with no stub raises a stray-request failure that the flow
        // swallows as "the media could not go out" — so an unstubbed endpoint
        // would make every media assertion below pass for the wrong reason.
        '*sendPhoto*' => Http::response(['ok' => true, 'result' => ['message_id' => 12]]),
        '*sendVoice*' => Http::response(['ok' => true, 'result' => ['message_id' => 13]]),
        '*sendVideo*' => Http::response(['ok' => true, 'result' => ['message_id' => 14]]),
        '*getFile*' => Http::response(['ok' => true, 'result' => ['file_id' => 'x', 'file_path' => 'photos/proof.jpg']]),
        'https://api.telegram.org/file/*' => Http::response('jpeg-bytes'),
    ]);
}

/**
 * A proof disk that accepts the download but cannot hand the bytes back.
 *
 * The one condition the flow cannot reach by ordinary means: `TelegramFileDownloader`
 * writes the file and `CheckInFlow` reads it back inside the same update, so the
 * only way to lose it in between is to make the disk lose it.
 */
function proofsVanishAfterTheyAreStored(): void
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturnTrue();
    $disk->shouldReceive('get')->andReturnNull();
    $disk->shouldReceive('exists')->andReturnFalse();

    Storage::set('local', $disk);
}

/**
 * A running challenge with a real timeline, pinned to a known token and proof
 * mechanic. Recording-proof challenges pass their caps through `$attributes`:
 * the columns are nullable and the factory writes no defaults, and a cap of
 * null reads as zero — refusing every recording as over the limit.
 *
 * @param  array<string, mixed>  $attributes
 */
function checkinChallenge(ProofType $proofType, string $token = 'checkintoken', array $attributes = []): Challenge
{
    $challenge = Challenge::factory()
        ->active()
        ->provenBy($proofType)
        ->create(array_merge(['join_token' => $token, 'title' => 'Morning run', 'total_periods' => 10], $attributes));

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    return $challenge;
}

/**
 * The caps a recording-proof challenge tests run under: generous enough that
 * the happy paths are happy, tight enough that an overlong or oversized test
 * fixture visibly exceeds them.
 *
 * @return array<string, int>
 */
function recordingCaps(): array
{
    return ['proof_media_max_seconds' => 120, 'proof_media_max_size_kb' => 4096];
}

/**
 * The challenge's creator, as a bot-reachable, gate-verified user.
 */
function theCreatorOf(Challenge $challenge, int $telegramId): User
{
    // `preferring('en')` pins the locale: the factory randomises between en and
    // fa, and a Farsi creator would rightly get the Farsi verdict lines.
    $creator = User::factory()->telegram($telegramId)->preferring('en')->create(['channel_verified_at' => now()]);
    $challenge->forceFill(['creator_id' => $creator->getKey()])->save();

    return $creator;
}

/**
 * A gate-verified user already active in the challenge.
 */
function aParticipantIn(Challenge $challenge, int $telegramId, string $locale = 'en'): array
{
    $user = User::factory()->telegram($telegramId)->preferring($locale)->create(['channel_verified_at' => now()]);
    $participant = ChallengeParticipant::factory()->for($challenge)->for($user)->create();

    return [$user, $participant];
}

/**
 * The period open right now.
 */
function currentPeriod(Challenge $challenge): ChallengePeriod
{
    return $challenge->periods()->containing(now())->first();
}

/**
 * A typed message, through the whole inbound path.
 */
function typesIn(int $telegramId, string $text): void
{
    $update = TelegramUpdate::factory()
        ->messageFrom(['id' => $telegramId, 'first_name' => 'Sara'], $text)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * A photo message, through the whole inbound path.
 */
function sendsPhoto(int $telegramId, string $fileId = 'AgACbig'): void
{
    $update = TelegramUpdate::factory()
        ->photoFrom(['id' => $telegramId, 'first_name' => 'Sara'], $fileId)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * A voice message, through the whole inbound path. Duration and size are what
 * Telegram itself measured, so a test that cares about the caps says so here
 * rather than the server re-deriving anything.
 */
function sendsVoice(int $telegramId, int $duration = 30, string $fileId = 'AwVoice-file-id', ?int $fileSize = null): void
{
    $update = TelegramUpdate::factory()
        ->voiceFrom(['id' => $telegramId, 'first_name' => 'Sara'], $duration, $fileId, $fileSize)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * A video message, through the whole inbound path — same shape as voice.
 */
function sendsVideo(int $telegramId, int $duration = 20, string $fileId = 'BaVideo-file-id', ?int $fileSize = null): void
{
    $update = TelegramUpdate::factory()
        ->videoFrom(['id' => $telegramId, 'first_name' => 'Sara'], $duration, $fileId, $fileSize)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * A tap, through the whole inbound path.
 */
function taps(string $data, int $telegramId): void
{
    $update = TelegramUpdate::factory()
        ->callbackQueryFrom(['id' => $telegramId, 'first_name' => 'Sara'], $data)
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

describe('/checkin', function () {
    it('lists what is owed, with a button per challenge', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::Button);
        aParticipantIn($challenge, 888_100_1);

        typesIn(888_100_1, '/checkin');

        expect(soleBotMessage()['text'])
            // `active()` opened the timeline yesterday, so today is the second
            // period — computed rather than assumed, like everywhere else.
            ->toContain(botCopy('bot.checkin.todo', [
                'title' => 'Morning run',
                'index' => currentPeriod($challenge)->index + 1,
                'total' => 10,
                'how' => botCopy('bot.checkin.how.button'),
            ]))
            ->and(botKeyboard())->toBe([[[
                'text' => botCopy('bot.checkin.button', ['title' => 'Morning run']),
                'callback_data' => 'ci:'.$challenge->join_token,
            ]]]);
    });

    it('says nothing is due when nothing is owed yet', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::Button);
        aParticipantIn($challenge, 888_100_1);

        // Scheduled, so no period has opened and the participant owes nothing.
        $challenge->forceFill(['status' => ChallengeStatus::Scheduled])->save();

        typesIn(888_100_1, '/checkin');

        // One challenge, so the mechanic is named — as its own paragraph, which
        // is what keeps a five-branch sentence from running into the reason it
        // is being said.
        expect(soleBotMessage()['text'])->toBe(
            botCopy('bot.checkin.nothing_due')."\n\n".botCopy('bot.checkin.how.button'),
        );
    });

    it('reports a settled period with the streak it earned', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::Button);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        $participant->forceFill(['current_streak' => 3])->save();
        CheckIn::factory()->on($participant, currentPeriod($challenge))->approved()->create();

        typesIn(888_100_1, '/checkin');

        expect(soleBotMessage()['text'])
            ->toBe(botCopy('bot.checkin.done', ['title' => 'Morning run', 'streak' => 3]))
            ->and(botKeyboard())->toBe([]);
    });

    it('says a photo is still with the creator rather than owed again', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::ImageApproval);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        CheckIn::factory()->on($participant, currentPeriod($challenge))->submitted()->create();

        typesIn(888_100_1, '/checkin');

        expect(soleBotMessage()['text'])
            ->toBe(botCopy('bot.checkin.awaiting_review', ['title' => 'Morning run']))
            ->and(botKeyboard())->toBe([]);
    });

    it('tells a user with no challenges so', function () {
        telegramServesTheLot();
        User::factory()->telegram(888_100_1)->preferring('en')->create(['channel_verified_at' => now()]);

        typesIn(888_100_1, '/checkin');

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.checkin.none'));
    });

    it('blocks at the gate like anything else', function () {
        Http::fake([
            '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'left']]),
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
        ]);

        $challenge = checkinChallenge(ProofType::Button);

        // Never verified: no cached "yes" for the gate to lean on.
        $user = User::factory()->telegram(888_100_1)->preferring('en')->create(['channel_verified_at' => null]);
        ChallengeParticipant::factory()->for($challenge)->for($user)->create();

        typesIn(888_100_1, '/checkin');

        expect(soleBotMessage()['text'])->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']));
    });
});

/*
 * The act, not the occasion. Every message that asks somebody to check in used
 * to name the period and stop, which tells a first-time participant nothing
 * about whether checking in means a tap, a typed phrase or a photograph — and
 * the answer differs per challenge, so it cannot live in the message.
 */
describe('the check-in instruction', function () {
    it('says what checking in means, per proof type', function (ProofType $proofType, array $attributes) {
        telegramServesTheLot();
        $challenge = checkinChallenge($proofType, attributes: $attributes);
        aParticipantIn($challenge, 888_100_1);

        typesIn(888_100_1, '/checkin');

        // Asserted against the rendered line, not the key it came from: the
        // failure worth catching is a sentence that names the wrong act, and a
        // key assertion would pass while the Farsi half said "photo" for a video.
        expect(soleBotMessage()['text'])->toContain(botCopy("bot.checkin.how.{$proofType->value}"));
    })->with([
        'a tap' => [ProofType::Button, []],
        'a typed phrase' => [ProofType::TextAutogen, []],
        'a photo' => [ProofType::ImageApproval, []],
        'a voice message' => [ProofType::VoiceApproval, recordingCaps()],
        'a video' => [ProofType::VideoApproval, recordingCaps()],
    ]);

    it('names the session instead, whatever a timed challenge proves with', function (ProofType $proofType) {
        telegramServesTheLot();
        $challenge = checkinChallenge($proofType, attributes: ['flow_type' => FlowType::TimedSession]);
        aParticipantIn($challenge, 888_100_1);

        typesIn(888_100_1, '/checkin');

        // A timed challenge still carries a proof type — the session's last step
        // ends in one — but what the participant *does* is start a session. The
        // flow overrides the proof, and the copy does not name one.
        expect(soleBotMessage()['text'])
            ->toContain(botCopy('bot.checkin.how.session'))
            ->not->toContain(botCopy("bot.checkin.how.{$proofType->value}"));
    })->with([
        'a tap' => [ProofType::Button],
        'a typed phrase' => [ProofType::TextAutogen],
        'a photo' => [ProofType::ImageApproval],
    ]);

    it('explains it in the participant’s own language', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::ImageApproval);
        aParticipantIn($challenge, 888_100_1, locale: 'fa');

        typesIn(888_100_1, '/checkin');

        expect(soleBotMessage()['text'])
            ->toContain(botCopy('bot.checkin.how.image_approval', [], 'fa'))
            ->not->toContain(botCopy('bot.checkin.how.image_approval'));
    });

    it('leaves the mechanic out when there is more than one challenge to name it for', function () {
        telegramServesTheLot();
        $tap = checkinChallenge(ProofType::Button, 'token-one');
        $photo = checkinChallenge(ProofType::ImageApproval, 'token-two');

        // Scheduled, so neither owes anything and the listing comes out empty.
        $tap->forceFill(['status' => ChallengeStatus::Scheduled])->save();
        $photo->forceFill(['status' => ChallengeStatus::Scheduled])->save();

        [$user] = aParticipantIn($tap, 888_100_1);
        ChallengeParticipant::factory()->for($photo)->for($user)->create();

        typesIn(888_100_1, '/checkin');

        // Two challenges, two mechanics. Naming one of them would be a coin flip
        // dressed up as an answer, and the listing states it properly per
        // challenge the moment something is actually owed.
        expect(soleBotMessage()['text'])->toBe(botCopy('bot.checkin.nothing_due'));
    });
});

describe('button proof', function () {
    it('checks in on the tap and reports the streak', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::Button);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        expect($participant->refresh())
            ->current_streak->toBe(1)
            ->and(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->status)
            ->toBe(CheckInStatus::Approved)
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.checkin.confirmed', ['title' => 'Morning run', 'streak' => 1]));
    });

    it('refuses a second tap on a settled period', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::Button);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        $data = BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token);
        taps($data, 888_100_1);
        taps($data, 888_100_1);

        expect(lastBotReply()['text'])
            ->toBe(botCopy('bot.checkin.refused.already_settled'))
            ->and($participant->refresh()->current_streak)->toBe(1);
    });

    it('refuses a tapper who is not in the challenge, writing nothing', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::Button);
        User::factory()->telegram(888_900_9)->preferring('en')->create(['channel_verified_at' => now()]);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_900_9);

        expect(lastBotReply()['text'])
            ->toBe(botCopy('bot.checkin.refused.not_a_participant', ['title' => 'Morning run']))
            ->and(CheckIn::query()->count())->toBe(0);
    });
});

describe('phrase proof', function () {
    it('shows the phrase and opens the conversation', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::TextAutogen);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        $checkIn = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole();

        // The string they were shown is the string their answer is compared
        // against — one phrase, persisted, not recomputed per attempt.
        expect(soleBotMessage()['text'])
            ->toContain(botCopy('bot.checkin.phrase_prompt', ['title' => 'Morning run']))
            ->toContain($checkIn->expected_phrase)
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInText);
    });

    it('approves the matching phrase and closes the conversation', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::TextAutogen);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        $phrase = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->expected_phrase;

        typesIn(888_100_1, $phrase);

        expect(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole())
            ->status->toBe(CheckInStatus::Approved)
            ->submitted_text->toBe($phrase)
            ->and($participant->refresh()->current_streak)->toBe(1)
            ->and(BotConversation::query()->where('user_id', $user->getKey())->exists())->toBeFalse()
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.checkin.confirmed', ['title' => 'Morning run', 'streak' => 1]));
    });

    it('re-asks on a wrong phrase, then still accepts the right one', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::TextAutogen);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        $phrase = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->expected_phrase;

        typesIn(888_100_1, 'definitely not the phrase');

        // A mismatch is the mechanic working: nothing settles, the conversation
        // holds, and the phrase is still one scroll up.
        expect(lastBotReply()['text'])->toBe(botCopy('bot.checkin.phrase_error'))
            ->and(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->status)
            ->toBe(CheckInStatus::Pending)
            ->and(BotConversation::query()->where('user_id', $user->getKey())->exists())->toBeTrue();

        typesIn(888_100_1, $phrase);

        expect(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->status)
            ->toBe(CheckInStatus::Approved)
            ->and($participant->refresh()->current_streak)->toBe(1);
    });

    it('re-asks when a photo arrives where words were due', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::TextAutogen);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        sendsPhoto(888_100_1);

        expect(lastBotReply()['text'])->toBe(botCopy('bot.checkin.phrase_expected'))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInText);
    });
});

describe('photo proof', function () {
    it('asks for the photo and opens the conversation', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::ImageApproval);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.checkin.photo_prompt', ['title' => 'Morning run']))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInPhoto);
    });

    it('stores the largest photo and hands the creator the verdict buttons', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::ImageApproval);
        $creator = theCreatorOf($challenge, 888_200_2);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        sendsPhoto(888_100_1);

        $checkIn = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole();

        expect($checkIn->status)->toBe(CheckInStatus::Submitted)
            ->and(Storage::disk('local')->exists($checkIn->proof_path))->toBeTrue()
            ->and(BotConversation::query()->where('user_id', $user->getKey())->exists())->toBeFalse();

        // The largest of the ladder is the one worth keeping — and the one asked
        // for from Telegram. `getFile` travels as a GET, so the id rides the URL.
        $getFile = Http::recorded(fn ($request) => str_contains($request->url(), 'getFile'))->first();
        expect($getFile)->not->toBeNull()
            ->and($getFile[0]->url())->toContain('file_id=AgACbig');

        // The participant hears the photo landed; the creator gets the photo
        // itself, with the verdict buttons riding on that same message's caption.
        // Before Phase 17 this asserted the review line was the last *text*
        // message — it is now a media send, so the assertion moved there rather
        // than being dropped.
        $messages = botMessages();

        expect($messages)->toHaveCount(2)
            ->and($messages[1]['text'])->toBe(botCopy('bot.checkin.photo_sent', ['title' => 'Morning run']));

        $media = botMediaMessages();

        expect($media)->toHaveCount(1)
            ->and($media[0]['endpoint'])->toBe('photo')
            ->and($media[0]['fields']['chat_id'])->toBe('8882002')
            ->and($media[0]['fields']['photo'])->toBe('jpeg-bytes')
            ->and($media[0]['fields']['caption'])
            ->toBe(botCopy('bot.checkin.review_prompt_image', ['name' => 'Sara', 'title' => 'Morning run']))
            ->and(keyboardOn($media[0]['fields']))->toBe([[
                [
                    'text' => botCopy('bot.checkin.approve_button'),
                    'callback_data' => BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::APPROVE),
                ],
                [
                    'text' => botCopy('bot.checkin.reject_button'),
                    'callback_data' => BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::REJECT),
                ],
            ]]);
    });

    it('re-asks when text arrives where a photo was due', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::ImageApproval);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        typesIn(888_100_1, 'here is my proof');

        expect(lastBotReply()['text'])->toBe(botCopy('bot.checkin.photo_expected'))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInPhoto);
    });

    it('keeps the flow open when the photo cannot be delivered', function () {
        Http::fake([
            '*answerCallbackQuery*' => Http::response(['ok' => true, 'result' => true]),
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
            '*getFile*' => Http::response(['ok' => true, 'result' => ['file_id' => 'x', 'file_path' => 'photos/proof.jpg']]),
            // Telegram answered, the bytes did not arrive.
            'https://api.telegram.org/file/*' => Http::response(''),
        ]);

        $challenge = checkinChallenge(ProofType::ImageApproval);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        sendsPhoto(888_100_1);

        // An infrastructure failure is ours, not theirs: nothing is recorded as
        // submitted, and the flow is still open for the second attempt.
        expect(lastBotReply()['text'])->toBe(botCopy('bot.checkin.photo_error'))
            ->and(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->count())->toBe(0)
            ->and(BotConversation::query()->where('user_id', $user->getKey())->exists())->toBeTrue();
    });
});

describe('recording proof', function () {
    it('asks for the voice message, with its caps, and opens the conversation', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::VoiceApproval, attributes: recordingCaps());
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.checkin.voice_prompt', [
            'title' => 'Morning run', 'max' => 120, 'size' => 4096,
        ]))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInVoice);
    });

    it('stores the voice message and hands the creator the recording', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::VoiceApproval, attributes: recordingCaps());
        theCreatorOf($challenge, 888_200_2);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        sendsVoice(888_100_1, duration: 45, fileSize: 204_800);

        $checkIn = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole();

        expect($checkIn->status)->toBe(CheckInStatus::Submitted)
            ->and($checkIn->proofKind())->toBe('voice')
            ->and(Storage::disk('local')->exists($checkIn->proof_path))->toBeTrue()
            ->and(BotConversation::query()->where('user_id', $user->getKey())->exists())->toBeFalse();

        // The participant hears it landed; the creator gets the recording — the
        // queue is no longer the only place a voice proof can be heard.
        $messages = botMessages();

        expect($messages)->toHaveCount(2)
            ->and($messages[1]['text'])->toBe(botCopy('bot.checkin.voice_sent', ['title' => 'Morning run']));

        $media = botMediaMessages();

        expect($media)->toHaveCount(1)
            ->and($media[0]['endpoint'])->toBe('voice')
            ->and($media[0]['fields']['chat_id'])->toBe('8882002')
            ->and($media[0]['fields']['caption'])
            ->toBe(botCopy('bot.checkin.review_prompt_voice', ['name' => 'Sara', 'title' => 'Morning run']))
            ->and(keyboardOn($media[0]['fields']))->toBe([[
                [
                    'text' => botCopy('bot.checkin.approve_button'),
                    'callback_data' => BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::APPROVE),
                ],
                [
                    'text' => botCopy('bot.checkin.reject_button'),
                    'callback_data' => BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::REJECT),
                ],
            ]]);
    });

    it('stores a video the same way, under the same caps', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::VideoApproval, attributes: recordingCaps());
        theCreatorOf($challenge, 888_200_2);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.checkin.video_prompt', [
            'title' => 'Morning run', 'max' => 120, 'size' => 4096,
        ]))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInVideo);

        sendsVideo(888_100_1, duration: 60, fileSize: 3_000_000);

        $checkIn = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole();

        expect($checkIn->status)->toBe(CheckInStatus::Submitted)
            ->and($checkIn->proofKind())->toBe('video');

        $media = botMediaMessages();

        expect($media)->toHaveCount(1)
            ->and($media[0]['endpoint'])->toBe('video')
            ->and($media[0]['fields']['caption'])
            ->toBe(botCopy('bot.checkin.review_prompt_video', ['name' => 'Sara', 'title' => 'Morning run']));
    });

    it('refuses an overlong recording before storing anything, and keeps the flow open', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::VoiceApproval, attributes: recordingCaps());
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        sendsVoice(888_100_1, duration: 121, fileSize: 204_800);

        // Refused on Telegram's own measurement, before a byte is kept: the
        // participant can send a shorter one into the same open flow.
        expect(lastBotReply()['text'])
            ->toBe(botCopy('bot.checkin.refused.media_too_long', ['title' => 'Morning run']))
            ->and(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->count())->toBe(0)
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInVoice);

        sendsVoice(888_100_1, duration: 90, fileSize: 204_800);

        expect(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->status)
            ->toBe(CheckInStatus::Submitted);
    });

    it('refuses an oversized recording the same way', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::VideoApproval, attributes: recordingCaps());
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        sendsVideo(888_100_1, duration: 60, fileSize: 5_000_000); // ~4883 KB > 4096

        expect(lastBotReply()['text'])
            ->toBe(botCopy('bot.checkin.refused.media_too_large', ['title' => 'Morning run']))
            ->and(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->count())->toBe(0)
            ->and(BotConversation::query()->where('user_id', $user->getKey())->exists())->toBeTrue();
    });

    it('re-asks when text arrives where a recording was due', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::VoiceApproval, attributes: recordingCaps());
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        typesIn(888_100_1, 'here is my proof');

        expect(lastBotReply()['text'])->toBe(botCopy('bot.checkin.voice_expected'))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInVoice);
    });
});

describe('the verdict', function () {
    it('approves on the creator’s tap and tells the participant their streak', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::ImageApproval);
        $creator = theCreatorOf($challenge, 888_200_2);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        $checkIn = CheckIn::factory()->on($participant, currentPeriod($challenge))->submitted()->create();

        taps(BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::APPROVE), 888_200_2);

        $messages = botMessages();

        expect($checkIn->refresh()->status)->toBe(CheckInStatus::Approved)
            ->and($participant->refresh()->current_streak)->toBe(1)
            ->and($messages[count($messages) - 2]['text'])->toBe(botCopy('bot.checkin.review_approved_ack'))
            ->and($messages[count($messages) - 1]['text'])
            ->toBe(botCopy('bot.checkin.review_approved', ['title' => 'Morning run', 'streak' => 1]));
    });

    it('rejects on the creator’s tap and lets the participant resubmit', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::ImageApproval);
        $creator = theCreatorOf($challenge, 888_200_2);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        $checkIn = CheckIn::factory()->on($participant, currentPeriod($challenge))->submitted()->create();

        taps(BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::REJECT), 888_200_2);

        $messages = botMessages();

        expect($checkIn->refresh()->status)->toBe(CheckInStatus::Rejected)
            ->and($participant->refresh()->current_streak)->toBe(0)
            ->and($messages[count($messages) - 1]['text'])
            ->toBe(botCopy('bot.checkin.review_rejected_image', ['title' => 'Morning run']));

        // A rejection is not an ending: the same photo flow accepts another one.
        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        sendsPhoto(888_100_1);

        expect($checkIn->refresh()->status)->toBe(CheckInStatus::Submitted);
    });

    it('refuses a verdict from somebody who is not the creator', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::ImageApproval);
        $creator = theCreatorOf($challenge, 888_200_2);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);
        User::factory()->telegram(888_900_9)->preferring('en')->create(['channel_verified_at' => now()]);

        $checkIn = CheckIn::factory()->on($participant, currentPeriod($challenge))->submitted()->create();

        taps(BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::APPROVE), 888_900_9);

        expect(lastBotReply()['text'])->toBe(botCopy('bot.checkin.review_refused.not_the_reviewer'))
            ->and($checkIn->refresh()->status)->toBe(CheckInStatus::Submitted)
            ->and($participant->refresh()->current_streak)->toBe(0);
    });

    it('refuses a second verdict on a decided row', function () {
        telegramServesTheLot();
        $challenge = checkinChallenge(ProofType::ImageApproval);
        $creator = theCreatorOf($challenge, 888_200_2);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        $checkIn = CheckIn::factory()->on($participant, currentPeriod($challenge))->submitted()->create();

        $approve = BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::APPROVE);
        taps($approve, 888_200_2);
        taps($approve, 888_200_2);

        expect(lastBotReply()['text'])->toBe(botCopy('bot.checkin.review_refused.already_settled'))
            ->and($participant->refresh()->current_streak)->toBe(1);
    });

    it('answers a verdict that names nothing as a stale button', function () {
        telegramServesTheLot();

        $user = User::factory()->telegram(888_200_2)->preferring('en')->create(['channel_verified_at' => now()]);

        taps(BotCallback::encode(ReviewCheckInCallback::ACTION, '99999999', ReviewCheckInCallback::APPROVE), 888_200_2);

        expect(lastBotReply()['text'])->toBe(botCopy('bot.fallback.stale_button'));
    });
});

/*
 * A quantity challenge: the proof ride is the same, and one question is added —
 * "how many?". Where it lands in the flow is per proof type: a tap and the
 * media proofs ask the number first (their verdict settles the moment the
 * evidence exists), the phrase asks it second (the phrase proves presence, the
 * number is judged). The confirmation quotes the number back with the score it
 * earned — that sentence is the whole feature, so it is asserted verbatim.
 */
describe('quantity scoring', function () {
    /**
     * A quantity challenge with the factory's pushup shape — target 30,
     * base 100 — so the arithmetic stays legible: 45 is 150 points, 15 is 50.
     */
    function quantityChallenge(ProofType $proofType, bool $partialCountsAsDone = false): Challenge
    {
        $challenge = Challenge::factory()
            ->active()
            ->provenBy($proofType)
            ->quantity(partialCountsAsDone: $partialCountsAsDone)
            ->create(['join_token' => 'scoretoken', 'title' => 'Morning run', 'total_periods' => 10]);

        app(MaterialiseChallengePeriods::class)->handle($challenge);

        return $challenge;
    }

    it('asks for the number before the tap settles it, and confirms with the score', function () {
        telegramServesTheLot();
        $challenge = quantityChallenge(ProofType::Button);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        // The tap did not settle anything: the number is the substance of the
        // report, so it is asked before the period is judged.
        expect(soleBotMessage()['text'])
            ->toBe(botCopy('bot.checkin.value_prompt', ['title' => 'Morning run', 'unit' => 'pushups']))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInValue)
            ->and(CheckIn::query()->count())->toBe(0);

        typesIn(888_100_1, '45');

        $checkIn = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole();

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->reported_value)->toBe('45.00')
            ->and($checkIn->score)->toBe('150.00')
            ->and($participant->refresh())
            ->current_streak->toBe(1)
            ->total_score->toBe('150.00')
            ->and(BotConversation::query()->where('user_id', $user->getKey())->exists())->toBeFalse()
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.checkin.confirmed_scored', [
                'title' => 'Morning run', 'value' => '45', 'unit' => 'pushups', 'score' => 150, 'streak' => 1,
            ]));
    });

    it('confirms a below-target report with its lower score when the creator opted in', function () {
        telegramServesTheLot();
        $challenge = quantityChallenge(ProofType::Button, partialCountsAsDone: true);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        typesIn(888_100_1, '15');

        $checkIn = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole();

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->score)->toBe('50.00')
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.checkin.confirmed_scored', [
                'title' => 'Morning run', 'value' => '15', 'unit' => 'pushups', 'score' => 50, 'streak' => 1,
            ]));
    });

    it('re-asks a number it cannot read, folding nothing into a guess', function () {
        telegramServesTheLot();
        $challenge = quantityChallenge(ProofType::Button);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        typesIn(888_100_1, 'lots of them');

        expect(lastBotReply()['text'])
            ->toBe(botCopy('bot.checkin.value_error', ['unit' => 'pushups']))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInValue)
            ->and(CheckIn::query()->count())->toBe(0);

        typesIn(888_100_1, '45');

        expect(CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->score)
            ->toBe('150.00');
    });

    it('asks the phrase first and the number after, settling on the pair', function () {
        telegramServesTheLot();
        $challenge = quantityChallenge(ProofType::TextAutogen);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        // Presence first: the phrase prompt is the same one a binary challenge
        // gets — the scoring design has not changed the mechanic.
        $phrase = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->expected_phrase;

        expect(soleBotMessage()['text'])->toContain(
            botCopy('bot.checkin.phrase_prompt', ['title' => 'Morning run']),
        );

        typesIn(888_100_1, $phrase);

        expect(lastBotReply()['text'])
            ->toBe(botCopy('bot.checkin.value_prompt', ['title' => 'Morning run', 'unit' => 'pushups']))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInValue);

        typesIn(888_100_1, '45');

        $checkIn = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole();

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->score)->toBe('150.00')
            ->and($checkIn->submitted_text)->toBe($phrase)
            ->and(lastBotReply()['text'])->toBe(botCopy('bot.checkin.confirmed_scored', [
                'title' => 'Morning run', 'value' => '45', 'unit' => 'pushups', 'score' => 150, 'streak' => 1,
            ]));
    });

    it('keeps the answered number when the stashed phrase turns out wrong', function () {
        telegramServesTheLot();
        $challenge = quantityChallenge(ProofType::TextAutogen);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        $phrase = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole()->expected_phrase;

        // The wrong phrase is stashed unjudged — the flow cannot know it is
        // wrong until the number arrives and the pair is submitted.
        typesIn(888_100_1, 'definitely not the phrase');
        typesIn(888_100_1, '45');

        expect(lastBotReply()['text'])->toBe(botCopy('bot.checkin.phrase_error'))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInText);

        // The retry is one question, not two: the number already answered rides
        // in the conversation and settles with the corrected phrase.
        typesIn(888_100_1, $phrase);

        $checkIn = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole();

        expect($checkIn->status)->toBe(CheckInStatus::Approved)
            ->and($checkIn->score)->toBe('150.00')
            ->and(BotConversation::query()->where('user_id', $user->getKey())->exists())->toBeFalse();
    });

    it('numbers the photo before taking it, and the verdict scores the stored number', function () {
        telegramServesTheLot();
        $challenge = quantityChallenge(ProofType::ImageApproval, partialCountsAsDone: true);
        theCreatorOf($challenge, 888_200_2);
        [$user, $participant] = aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);

        expect(soleBotMessage()['text'])
            ->toBe(botCopy('bot.checkin.value_prompt', ['title' => 'Morning run', 'unit' => 'pushups']));

        typesIn(888_100_1, '20');

        expect(lastBotReply()['text'])->toBe(botCopy('bot.checkin.photo_prompt', ['title' => 'Morning run']))
            ->and(BotConversation::query()->where('user_id', $user->getKey())->sole()->state)
            ->toBe(ConversationState::AwaitingCheckInPhoto);

        sendsPhoto(888_100_1);

        $checkIn = CheckIn::query()->where('challenge_participant_id', $participant->getKey())->sole();

        // The number is on the row *before* review: the creator's verdict may
        // land hours later, and it scores the evidence the participant saw
        // confirmed, not a number re-supplied at verdict time.
        expect($checkIn->status)->toBe(CheckInStatus::Submitted)
            ->and($checkIn->reported_value)->toBe('20.00');

        taps(BotCallback::encode(ReviewCheckInCallback::ACTION, (string) $checkIn->getKey(), ReviewCheckInCallback::APPROVE), 888_200_2);

        $messages = botMessages();

        expect($checkIn->refresh()->score)->toBe('67.00')
            ->and($participant->refresh()->total_score)->toBe('67.00')
            ->and($messages[count($messages) - 1]['text'])
            ->toBe(botCopy('bot.checkin.review_approved_scored', [
                'title' => 'Morning run', 'value' => '20', 'unit' => 'pushups', 'score' => 67, 'streak' => 1,
            ]));
    });
});

describe('the creator notification when the proof cannot be sent', function () {
    it('hands over the text and the buttons when the proof has vanished from disk', function () {
        // A creator who can still approve from a sentence is better served than
        // one who hears nothing, so the media failing is a downgrade, not a
        // dropped review.
        telegramServesTheLot();
        Log::spy();
        proofsVanishAfterTheyAreStored();

        $challenge = checkinChallenge(ProofType::ImageApproval);
        theCreatorOf($challenge, 888_200_2);
        aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        sendsPhoto(888_100_1);

        expect(botMediaMessages())->toBeEmpty()
            ->and(lastBotReply()['text'])
            ->toBe(botCopy('bot.checkin.review_prompt_image', ['name' => 'Sara', 'title' => 'Morning run']))
            ->and(lastBotKeyboard()[0])->toHaveCount(2);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'missing from disk'))
            ->once();
    });

    it('hands over the text and the buttons when the platform refuses the upload', function () {
        telegramServesTheLot([
            '*sendPhoto*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: PHOTO_INVALID_DIMENSIONS',
            ], 400),
        ]);
        Log::spy();

        $challenge = checkinChallenge(ProofType::ImageApproval);
        theCreatorOf($challenge, 888_200_2);
        aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        sendsPhoto(888_100_1);

        // The submission itself still stands — only the notification degraded.
        expect(CheckIn::query()->sole()->status)->toBe(CheckInStatus::Submitted)
            ->and(lastBotReply()['text'])
            ->toBe(botCopy('bot.checkin.review_prompt_image', ['name' => 'Sara', 'title' => 'Morning run']))
            ->and(lastBotKeyboard()[0])->toHaveCount(2);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'could not be sent to its creator'))
            ->once();
    });

    it('logs rather than sending when the creator has no messenger identity', function () {
        telegramServesTheLot();
        Log::spy();

        $challenge = checkinChallenge(ProofType::ImageApproval);

        // An email-only admin created this challenge — an import, say. There is
        // no chat to send to, and the review queue already holds the submission.
        $admin = User::factory()->create(['channel_verified_at' => now()]);
        $challenge->forceFill(['creator_id' => $admin->getKey()])->save();

        aParticipantIn($challenge, 888_100_1);

        taps(BotCallback::encode(CheckInCallback::ACTION, $challenge->join_token), 888_100_1);
        sendsPhoto(888_100_1);

        expect(botMediaMessages())->toBeEmpty();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message): bool => str_contains($message, 'awaits a creator the bot cannot message'))
            ->once();
    });
});
