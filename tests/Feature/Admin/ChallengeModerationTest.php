<?php

use App\Enums\ChallengeStatus;
use App\Enums\ParticipantStatus;
use App\Jobs\Telegram\SendBotMessage;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
 * The moderation listing and its one lever: cancellation. The listing is a
 * read; the cancellation test cares that it goes through `CancelChallenge`
 * (creator-or-admin, one-way door, staggered notifications) rather than a bare
 * status write. Those rules' own tests live in the Domain suite.
 */

function anAdminModerator(): User
{
    return User::factory()->admin()->create();
}

it('refuses a non-admin on the moderation routes', function (string $method, string $uri): void {
    $this->actingAs(User::factory()->create())->{$method}($uri)->assertForbidden();
})->with([
    'list' => ['get', '/admin/challenges'],
    'show' => ['get', '/admin/challenges/1'],
    'cancel' => ['post', '/admin/challenges/1/cancel'],
]);

it('lists challenges newest first with their shape', function (): void {
    Queue::fake();
    $older = Challenge::factory()->create(['title' => 'Older one']);
    $newer = Challenge::factory()->active()->create(['title' => 'Newer one']);

    ChallengeParticipant::factory()->for($newer)->count(3)->create();

    $this->actingAs(anAdminModerator())->get('/admin/challenges')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Admin/Challenges')
            ->has('challenges', 2)
            ->where('challenges.0.title', 'Newer one')
            ->where('challenges.0.status.value', 'active')
            ->where('challenges.0.status.label', 'Active')
            ->where('challenges.0.participants_count', 3)
            ->where('challenges.1.title', 'Older one')
            ->where('challenges.1.participants_count', 0),
    );
});

it('filters by status and by title', function (): void {
    Queue::fake();
    Challenge::factory()->active()->create(['title' => 'Morning pages']);
    Challenge::factory()->completed()->create(['title' => 'Morning pages the sequel']);
    Challenge::factory()->active()->create(['title' => 'Evening run']);

    $this->actingAs(anAdminModerator())->get('/admin/challenges?status=active')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('challenges', 2));

    $this->actingAs(anAdminModerator())->get('/admin/challenges?status=active&q=Morning')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('challenges', 1)
            ->where('challenges.0.title', 'Morning pages'));

    // An unknown status reads as "no filter" rather than an error.
    $this->actingAs(anAdminModerator())->get('/admin/challenges?status=nonsense')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('challenges', 3));
});

it('shows one challenge with its participants and their streaks', function (): void {
    Queue::fake();
    $challenge = Challenge::factory()->active()->create(['title' => 'Evening stretch']);

    $participantUser = User::factory()->telegram()->create(['first_name' => 'Sahar']);
    ChallengeParticipant::factory()
        ->for($challenge)
        ->for($participantUser)
        ->withStreak(4, 7)
        ->withFreezes(2, 1)
        ->create();

    $this->actingAs(anAdminModerator())->get("/admin/challenges/{$challenge->getKey()}")
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Admin/Challenges/Show')
                ->where('challenge.title', 'Evening stretch')
                ->where('challenge.status.value', 'active')
                ->where('challenge.cancellable', true)
                ->has('participants', 1)
                ->where('participants.0.name', 'Sahar')
                ->where('participants.0.streak', 4)
                ->where('participants.0.longest_streak', 7)
                ->where('participants.0.freezes_used', 1)
                ->where('participants.0.freezes_total', 2),
        );
});

it('cancels a running challenge and queues a staggered notification per active participant', function (): void {
    Queue::fake();
    $challenge = Challenge::factory()->active()->create(['title' => 'Evening stretch']);

    $active = User::factory()->count(3)->create();
    foreach ($active as $user) {
        ChallengeParticipant::factory()->for($challenge)->for($user)->create();
    }

    $left = ChallengeParticipant::factory()->for($challenge)->create();
    $left->update(['status' => ParticipantStatus::Left]);

    $this->actingAs(anAdminModerator())
        ->from("/admin/challenges/{$challenge->getKey()}")
        ->post("/admin/challenges/{$challenge->getKey()}/cancel")
        ->assertRedirect("/admin/challenges/{$challenge->getKey()}");

    expect($challenge->refresh()->status)->toBe(ChallengeStatus::Cancelled);

    Queue::assertPushed(SendBotMessage::class, 3);
    Queue::assertPushed(SendBotMessage::class, fn (SendBotMessage $job): bool => $job->line === 'bot.challenge.cancelled');

    // Every active participant, spaced a second apart — the delay is the whole
    // point of the fan-out, and a blast would trip Telegram's rate limit.
    $delays = collect(Queue::pushed(SendBotMessage::class))
        ->map(fn (SendBotMessage $job) => $job->delay)
        ->sort()
        ->values()
        ->all();

    expect($delays)->toBe([0, 1, 2]);
});

it('refuses to cancel a challenge that is already over, with a toast', function (): void {
    Queue::fake();
    $challenge = Challenge::factory()->completed()->create();

    $this->actingAs(anAdminModerator())
        ->from("/admin/challenges/{$challenge->getKey()}")
        ->post("/admin/challenges/{$challenge->getKey()}/cancel")
        ->assertRedirect("/admin/challenges/{$challenge->getKey()}");

    expect($challenge->refresh()->status)->toBe(ChallengeStatus::Completed)
        ->and(Queue::pushed(SendBotMessage::class))->toBeEmpty();
});
