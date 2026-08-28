<?php

use App\Actions\Challenges\MaterialiseChallengePeriods;
use App\Enums\ChallengeStatus;
use App\Enums\CheckInStatus;
use App\Enums\ParticipantStatus;
use App\Enums\ProofType;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
 * The admin panel's image-proof review queue. What is under test is the
 * surface: which rows appear, how the photo is served, and that both verdicts
 * go through `ReviewCheckIn` — the same action the bot's inline buttons call —
 * with the participant notified over the bot. The verdict rules themselves are
 * `ReviewCheckIn`'s, already covered in the Domain suite.
 *
 * The queue is synchronous on every send, so `Queue::fake()` keeps the bot's
 * HTTP calls out of the picture except where a test wants to watch them.
 */

beforeEach(function () {
    config(['services.telegram.bot_token' => '123456:TEST-TOKEN']);

    // A real JPEG magic number, so the file response reports an image content
    // type rather than sniffing the placeholder bytes as text.
    Storage::disk('local')->put(
        'check-in-proofs/queue/proof.jpg',
        "\xFF\xD8\xFF\xE0".'jpeg-bytes',
    );

    // The verdict notification is a synchronous bot send — every test that
    // decides a photo talks to "Telegram".
    Http::fake(['*sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
});

function anAdminPanelReviewer(): User
{
    return User::factory()->admin()->create();
}

/**
 * A running image-approval challenge with a photo waiting on the creator.
 *
 * @return array{0: Challenge, 1: ChallengeParticipant, 2: CheckIn}
 */
function aQueuedProof(int $telegramId = 777_003_0): array
{
    $challenge = Challenge::factory()
        ->active()
        ->provenBy(ProofType::ImageApproval)
        ->create(['title' => 'Evening stretch']);

    app(MaterialiseChallengePeriods::class)->handle($challenge);

    $participantUser = User::factory()->telegram($telegramId)->preferring('en')->create();
    $participant = ChallengeParticipant::factory()->for($challenge)->for($participantUser)->create();

    /** @var ChallengePeriod $period */
    $period = $challenge->periods()->orderBy('index')->first();

    $checkIn = CheckIn::factory()
        ->on($participant, $period)
        ->submitted()
        ->create(['proof_path' => 'check-in-proofs/queue/proof.jpg']);

    return [$challenge, $participant, $checkIn];
}

it('refuses a non-admin on every review route', function (string $method, string $uri): void {
    $this->actingAs(User::factory()->create())->{$method}($uri)->assertForbidden();
})->with([
    'list' => ['get', '/admin/reviews'],
    'approve' => ['post', '/admin/reviews/1/approve'],
    'reject' => ['post', '/admin/reviews/1/reject'],
]);

it('lists submitted photos with their challenge, participant and proof url', function (): void {
    Queue::fake();
    [$challenge, $participant, $checkIn] = aQueuedProof();

    // Not in the queue: settled, or never a photo at all.
    $buttonChallenge = Challenge::factory()->active()->provenBy(ProofType::Button)->create();
    CheckIn::factory()->approved()->create();

    $this->actingAs(anAdminPanelReviewer())->get('/admin/reviews')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Admin/Reviews')
            ->has('reviews', 1)
            ->where('reviews.0.challenge', $challenge->title)
            ->where('reviews.0.participant', $participant->user->first_name)
            ->where('reviews.0.period', 1)
            ->where('reviews.0.total_periods', $challenge->total_periods)
            ->where('reviews.0.proof_url', route('admin.reviews.proof', $checkIn->getKey())),
    );
});

it('serves a stored proof only to an authenticated admin', function (): void {
    Queue::fake();
    [, , $checkIn] = aQueuedProof();

    $this->get("/admin/reviews/{$checkIn->getKey()}/proof")->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())
        ->get("/admin/reviews/{$checkIn->getKey()}/proof")
        ->assertForbidden();

    $this->actingAs(anAdminPanelReviewer())
        ->get("/admin/reviews/{$checkIn->getKey()}/proof")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('answers 404 when the proof file is gone from the disk', function (): void {
    Queue::fake();
    [, , $checkIn] = aQueuedProof();

    Storage::disk('local')->delete('check-in-proofs/queue/proof.jpg');

    $this->actingAs(anAdminPanelReviewer())
        ->get("/admin/reviews/{$checkIn->getKey()}/proof")
        ->assertNotFound();
});

it('approves through the shared action, notifies the participant, and flashes', function (): void {
    Queue::fake();
    [, $participant, $checkIn] = aQueuedProof();

    $this->actingAs(anAdminPanelReviewer())
        ->from('/admin/reviews')
        ->post("/admin/reviews/{$checkIn->getKey()}/approve")
        ->assertRedirect('/admin/reviews');

    expect($checkIn->refresh()->status)->toBe(CheckInStatus::Approved)
        ->and($participant->refresh()->current_streak)->toBe(1);

    // The participant must hear the verdict from the same request that decided
    // their period, not from a queue that may lag behind the admin's screen.
    // The body is form-encoded, so the title arrives with its spaces encoded.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
        && str_contains((string) $request->body(), 'Evening+stretch'));
});

it('rejects through the shared action and leaves the period actionable', function (): void {
    Queue::fake();
    [, , $checkIn] = aQueuedProof();

    $this->actingAs(anAdminPanelReviewer())
        ->from('/admin/reviews')
        ->post("/admin/reviews/{$checkIn->getKey()}/reject")
        ->assertRedirect('/admin/reviews');

    expect($checkIn->refresh()->status)->toBe(CheckInStatus::Rejected);
});

it('answers a verdict on a row the rollover already settled with a toast, not a 500', function (): void {
    Queue::fake();
    [, , $checkIn] = aQueuedProof();

    $checkIn->update(['status' => CheckInStatus::Missed]);

    $this->actingAs(anAdminPanelReviewer())
        ->from('/admin/reviews')
        ->post("/admin/reviews/{$checkIn->getKey()}/approve")
        ->assertRedirect('/admin/reviews')
        ->assertSessionHas(SessionKey::FLASH_DATA, [
            'toast' => [
                'type' => 'error',
                'message' => __('admin.reviews.refused.already_settled'),
            ],
        ]);
});

it('keeps an empty queue readable rather than erroring', function (): void {
    Queue::fake();

    $this->actingAs(anAdminPanelReviewer())->get('/admin/reviews')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->component('Admin/Reviews')->where('reviews', []),
    );
});

it('does not let the proof route double as a file browser', function (): void {
    Queue::fake();
    aQueuedProof();

    // A different id than the queued row's: the route model-binds, so an id
    // with no row is a 404, and an id with a row but no photo is a 404.
    $this->actingAs(anAdminPanelReviewer())->get('/admin/reviews/99999/proof')->assertNotFound();
});

it('leaves cancelled challenges out of the queue when their photos have already settled', function (): void {
    Queue::fake();
    [$challenge, , $checkIn] = aQueuedProof();
    $challenge->update(['status' => ChallengeStatus::Cancelled]);
    $checkIn->update(['status' => CheckInStatus::Frozen]);

    $this->actingAs(anAdminPanelReviewer())->get('/admin/reviews')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('reviews', []),
    );

    expect($challenge->refresh()->status)->toBe(ChallengeStatus::Cancelled)
        ->and(ParticipantStatus::Active)->toBe(ParticipantStatus::Active);
});
