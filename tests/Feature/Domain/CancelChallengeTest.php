<?php

use App\Actions\Challenges\CancelChallenge;
use App\Enums\ChallengeStatus;
use App\Enums\ParticipantStatus;
use App\Exceptions\ChallengeNotCancellable;
use App\Jobs\Telegram\SendBotMessage;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * `CancelChallenge`'s own rules — the panel is only one caller, and the action
 * is what a creator-side surface will call too. The panel-level behaviour is
 * covered by the moderation suite; what matters here is who may cancel, that
 * the door only swings one way, and what the participants hear.
 */

function aCancellationActor(): array
{
    $creator = User::factory()->telegram()->create();
    $challenge = Challenge::factory()->active()->create(['creator_id' => $creator->getKey()]);

    return [$creator, $challenge];
}

it('lets the creator cancel their own challenge', function (): void {
    Queue::fake();
    [$creator, $challenge] = aCancellationActor();

    app(CancelChallenge::class)->handle($creator, $challenge);

    expect($challenge->refresh()->status)->toBe(ChallengeStatus::Cancelled);
});

it('lets a platform admin cancel a challenge they did not create', function (): void {
    Queue::fake();
    [, $challenge] = aCancellationActor();
    $admin = User::factory()->admin()->create();

    app(CancelChallenge::class)->handle($admin, $challenge);

    expect($challenge->refresh()->status)->toBe(ChallengeStatus::Cancelled);
});

it('refuses anyone else, without touching the challenge', function (): void {
    Queue::fake();
    [, $challenge] = aCancellationActor();
    $bystander = User::factory()->create();

    app(CancelChallenge::class)->handle($bystander, $challenge);
})->throws(ChallengeNotCancellable::class, 'may only be cancelled');

it('refuses a second cancellation of an already-terminal challenge', function (): void {
    Queue::fake();
    [$creator, $challenge] = aCancellationActor();

    app(CancelChallenge::class)->handle($creator, $challenge);

    app(CancelChallenge::class)->handle($creator, $challenge);
})->throws(ChallengeNotCancellable::class, 'can no longer be cancelled');

it('notifies active participants and only them, staggered', function (): void {
    Queue::fake();
    [, $challenge] = aCancellationActor();

    $active = User::factory()->count(2)->create();
    foreach ($active as $user) {
        ChallengeParticipant::factory()->for($challenge)->for($user)->create();
    }
    ChallengeParticipant::factory()->for($challenge)->create([
        'status' => ParticipantStatus::Removed,
    ]);

    app(CancelChallenge::class)->handle(User::factory()->admin()->create(), $challenge);

    Queue::assertPushed(SendBotMessage::class, 2);
    Queue::assertPushed(SendBotMessage::class, fn (SendBotMessage $job): bool => $job->line === 'bot.challenge.cancelled');

    $delays = collect(Queue::pushed(SendBotMessage::class))
        ->map(fn (SendBotMessage $job) => $job->delay)
        ->sort()
        ->values()
        ->all();

    expect($delays)->toBe([0, 1]);
});

it('queues nothing when there is nobody active to tell', function (): void {
    Queue::fake();
    [, $challenge] = aCancellationActor();

    app(CancelChallenge::class)->handle(User::factory()->admin()->create(), $challenge);

    expect($challenge->refresh()->status)->toBe(ChallengeStatus::Cancelled)
        ->and(Queue::pushed(SendBotMessage::class))->toBeEmpty();
});
