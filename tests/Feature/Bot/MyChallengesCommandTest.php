<?php

use App\Enums\ChallengeStatus;
use App\Enums\ParticipantStatus;
use App\Enums\SettingKey;
use App\Jobs\Telegram\ProcessTelegramUpdate;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\Callbacks\CommandCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * `/challenges` — the listing the product did not have.
 *
 * A user with three challenges had nowhere that named them all: check-in reached
 * them one at a time from a reminder, and a challenge they had created and
 * finished simply vanished. What this file pins is the merge — participation and
 * ownership are separate sets, they overlap, and the overlap is named once.
 *
 * Users here arrive pre-verified, so the gate answers from `channel_verified_at`
 * and `getChatMember` is only needed by the test that is about the gate.
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

function telegramAnswersListings(): void
{
    Http::fake([
        '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'member']]),
        '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
    ]);
}

/**
 * A user who reads English and has already cleared the gate.
 */
function challengesViewer(int $telegramId, string $locale = 'en'): User
{
    return User::factory()->telegram($telegramId)->preferring($locale)->create([
        'channel_verified_at' => now(),
    ]);
}

/**
 * A challenge owned by somebody.
 *
 * Deliberately without a materialised timeline: this listing reads challenge rows
 * and participant rows, and never asks what period is open — that rule belongs to
 * `CheckInFlow` and reaching for it here would be a second copy of it.
 *
 * @param  array<string, mixed>  $attributes
 */
function listedChallenge(User $creator, array $attributes = []): Challenge
{
    return Challenge::factory()->active()->create($attributes + ['creator_id' => $creator->getKey()]);
}

/**
 * Somebody standing in a challenge, in whatever state the test needs.
 */
function standsIn(
    User $user,
    Challenge $challenge,
    ParticipantStatus $status = ParticipantStatus::Active,
    int $streak = 0,
): ChallengeParticipant {
    return ChallengeParticipant::factory()->for($challenge)->for($user)->create([
        'status' => $status,
        'current_streak' => $streak,
        'longest_streak' => $streak,
    ]);
}

/**
 * `/challenges` typed, through the whole inbound path.
 */
function asksForChallenges(int $telegramId): void
{
    $update = TelegramUpdate::factory()
        ->messageFrom(['id' => $telegramId, 'first_name' => 'Sara'], '/challenges')
        ->create();

    dispatch_sync(new ProcessTelegramUpdate($update));
}

/**
 * The listing's paragraphs, as the user reads them.
 *
 * @return list<string>
 */
function listingParagraphs(): array
{
    return explode("\n\n", (string) soleBotMessage()['text']);
}

describe('what the listing names', function () {
    it('says a participant is in it, and how the streak is going', function () {
        telegramAnswersListings();
        $viewer = challengesViewer(888_200_1);
        $challenge = listedChallenge(challengesViewer(888_200_2));

        standsIn($viewer, $challenge, streak: 4);

        asksForChallenges(888_200_1);

        expect(listingParagraphs())->toBe([botCopy('bot.challenges.row_participant', [
            'title' => $challenge->title,
            'status' => botCopy('enums.challenge_status.active'),
            'streak' => 4,
        ])]);
    });

    it('says a creator owns it, and how many others are in it', function () {
        telegramAnswersListings();
        $viewer = challengesViewer(888_200_3);
        $challenge = listedChallenge($viewer);

        // The creator is not a participant — `CreateChallenge` writes no
        // participation row for them — so this is the only way this challenge
        // reaches the listing at all.
        standsIn(challengesViewer(888_200_4), $challenge);
        standsIn(challengesViewer(888_200_5), $challenge);

        asksForChallenges(888_200_3);

        expect(listingParagraphs())->toBe([botCopy('bot.challenges.row_creator', [
            'title' => $challenge->title,
            'status' => botCopy('enums.challenge_status.active'),
            'people' => 2,
        ])]);
    });

    it('names a challenge the creator joined only once, as both', function () {
        telegramAnswersListings();
        $viewer = challengesViewer(888_200_6);
        $challenge = listedChallenge($viewer);

        // A creator *can* join their own challenge. They are then in both sets,
        // and a merge that concatenated would name this challenge twice.
        standsIn($viewer, $challenge);
        standsIn(challengesViewer(888_200_7), $challenge);

        asksForChallenges(888_200_6);

        // Two participants, one of whom is the reader: "theirs and joined · 1 in
        // it", not 2. Counting the reader among the other people would be wrong.
        expect(listingParagraphs())->toBe([botCopy('bot.challenges.row_both', [
            'title' => $challenge->title,
            'status' => botCopy('enums.challenge_status.active'),
            'people' => 1,
            'streak' => 0,
        ])]);
    });

    it('keeps a finished or abandoned challenge on the list', function () {
        telegramAnswersListings();
        $viewer = challengesViewer(888_200_8);

        $finished = listedChallenge(challengesViewer(888_200_9), [
            'title' => 'Finished one',
            'status' => ChallengeStatus::Completed,
        ]);
        $abandoned = listedChallenge(challengesViewer(888_201_0), ['title' => 'Abandoned one']);

        standsIn($viewer, $finished, ParticipantStatus::Completed);
        standsIn($viewer, $abandoned, ParticipantStatus::Left);

        asksForChallenges(888_200_8);

        // Both statuses, not just the active ones. A challenge a user walked away
        // from is still a thing they were in, and the streak they broke with it is
        // part of the record; a self-cleaning list would be a list they cannot
        // trust to be complete.
        expect(listingParagraphs())->toBe([
            botCopy('bot.challenges.row_participant', [
                'title' => 'Abandoned one',
                'status' => botCopy('enums.challenge_status.active'),
                'streak' => 0,
            ]),
            botCopy('bot.challenges.row_participant', [
                'title' => 'Finished one',
                'status' => botCopy('enums.challenge_status.completed'),
                'streak' => 0,
            ]),
        ]);
    });

    it('puts the newest first, whether it was joined or created', function () {
        telegramAnswersListings();
        $viewer = challengesViewer(888_201_1);

        $oldest = listedChallenge(challengesViewer(888_201_2), ['title' => 'Oldest']);
        $middle = listedChallenge(challengesViewer(888_201_3), ['title' => 'Middle']);
        $newest = listedChallenge($viewer, ['title' => 'Newest']);

        standsIn($viewer, $oldest);
        standsIn($viewer, $middle);

        asksForChallenges(888_201_1);

        // One order across both sets rather than two blocks: participation order
        // and creation order would each look right on their own and be arbitrary
        // read together.
        expect(listingParagraphs())->toBe([
            botCopy('bot.challenges.row_creator', [
                'title' => 'Newest',
                'status' => botCopy('enums.challenge_status.active'),
                'people' => 0,
            ]),
            botCopy('bot.challenges.row_participant', [
                'title' => 'Middle',
                'status' => botCopy('enums.challenge_status.active'),
                'streak' => 0,
            ]),
            botCopy('bot.challenges.row_participant', [
                'title' => 'Oldest',
                'status' => botCopy('enums.challenge_status.active'),
                'streak' => 0,
            ]),
        ]);
    });

    it('speaks the reader’s language, not the ambient one', function () {
        telegramAnswersListings();
        $viewer = challengesViewer(888_201_4, locale: 'fa');
        $challenge = listedChallenge($viewer, ['title' => 'دویدن صبحگاهی']);

        asksForChallenges(888_201_4);

        expect(listingParagraphs())->toBe([botCopy('bot.challenges.row_creator', [
            'title' => 'دویدن صبحگاهی',
            'status' => botCopy('enums.challenge_status.active', [], 'fa'),
            'people' => 0,
        ], 'fa')]);
    });
});

describe('the edges of the listing', function () {
    it('caps the list and counts what it left off', function () {
        telegramAnswersListings();
        $viewer = challengesViewer(888_201_5);

        // Thirteen, so the cap bites and the overflow is not a round number an
        // off-by-one could arrive at by accident.
        $titles = [];

        foreach (range(1, 13) as $index) {
            $titles[] = "Challenge {$index}";
            listedChallenge($viewer, ['title' => "Challenge {$index}"]);
        }

        asksForChallenges(888_201_5);

        $paragraphs = listingParagraphs();

        // The newest ten, then the count — and the three that fell off are
        // *counted*, not dropped silently, so nobody reads ten of their fifteen
        // challenges and concludes the other five are gone.
        $expected = array_map(
            static fn (string $title): string => botCopy('bot.challenges.row_creator', [
                'title' => $title,
                'status' => botCopy('enums.challenge_status.active'),
                'people' => 0,
            ]),
            array_reverse(array_slice($titles, 3)),
        );

        expect($paragraphs)->toHaveCount(11)
            ->and(array_slice($paragraphs, 0, 10))->toBe($expected)
            ->and($paragraphs[10])->toBe(botCopy('bot.challenges.more', ['count' => 3]));
    });

    it('offers a door rather than a diagnosis to somebody with nothing', function () {
        telegramAnswersListings();
        challengesViewer(888_201_6);

        asksForChallenges(888_201_6);

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.challenges.none'))
            ->and(botKeyboard())->toBe([[commandButton('en', 'create')]]);
    });

    it('offers the two ways in from a listing that has something in it', function () {
        telegramAnswersListings();
        $viewer = challengesViewer(888_201_7);
        $challenge = listedChallenge($viewer);

        asksForChallenges(888_201_7);

        expect(botKeyboard())->toBe([[
            commandButton('en', 'checkin'),
            commandButton('en', 'create'),
        ]]);
    });

    it('blocks at the gate like anything else', function () {
        Http::fake([
            '*getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'left']]),
            '*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]]),
        ]);

        $viewer = challengesViewer(888_201_8);
        $viewer->forceFill(['channel_verified_at' => null])->save();
        listedChallenge($viewer, ['title' => 'Should not be named']);

        asksForChallenges(888_201_8);

        // The gate comes before the listing, not after it: the reply must not
        // name a challenge to somebody who is not allowed to act on one.
        expect(soleBotMessage()['text'])
            ->toContain(botCopy('bot.gate.blocked', ['channel' => '@challenges']))
            ->not->toContain('Should not be named');
    });

    it('reaches the same listing whether it was typed or tapped', function () {
        telegramAnswersListings();
        $viewer = challengesViewer(888_201_9);
        $challenge = listedChallenge($viewer, ['title' => 'Tapped, not typed']);

        // The welcome's own button is `cm:challenges`, which `CommandCallback`
        // routes as `BotCommand::named('challenges')` — byte-for-byte what typing
        // it produces, so the two cannot drift.
        $update = TelegramUpdate::factory()
            ->callbackQueryFrom(
                ['id' => 888_201_9, 'first_name' => 'Sara'],
                BotCallback::encode(CommandCallback::ACTION, 'challenges'),
            )
            ->create();

        dispatch_sync(new ProcessTelegramUpdate($update));

        expect(soleBotMessage()['text'])->toBe(botCopy('bot.challenges.row_creator', [
            'title' => $challenge->title,
            'status' => botCopy('enums.challenge_status.active'),
            'people' => 0,
        ]));
    });
});
