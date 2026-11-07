<?php

use App\Models\AiProviderAccount;
use App\Models\AiUsageRecord;
use App\Models\AiUsageReservation;
use App\Models\User;
use App\Services\Ai\AiQuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function anAiUsageAdmin(): User
{
    return User::factory()->admin()->create();
}

/*
| The factories randomise token counts and these tests assert exact sums, so
| every seeded row is pinned through these helpers.
*/
function seedRecord(AiProviderAccount $account, int $input, int $output, array $extra = []): AiUsageRecord
{
    return AiUsageRecord::factory()->create(array_merge([
        'ai_provider_account_id' => $account->id,
        'input_tokens' => $input,
        'output_tokens' => $output,
        'total_tokens' => $input + $output,
    ], $extra));
}

it('redirects a guest and refuses a non-admin', function (): void {
    $this->get('/admin/ai-usage')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get('/admin/ai-usage')->assertForbidden();
});

it('reports window totals that equal the quota service, not a naive SUM', function (): void {
    $account = AiProviderAccount::factory()->configured()->create();

    seedRecord($account, 100, 50);
    seedRecord($account, 999, 999, ['created_at' => now()->subDays(10)]);

    // The half-open window ends at a strict `<` on whole-second DATETIMEs, so
    // a record from the same wall-clock second as the page load would fall
    // outside it. Advancing the clock makes the page's window deterministic
    // without touching the service's semantics.
    $this->travel(1)->minutes();

    $this->actingAs(anAiUsageAdmin())->get('/admin/ai-usage')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Admin/AiUsage')
            ->where('window', '7d')
            ->where('totals', app(AiQuotaService::class)->consumedBetween(
                CarbonImmutable::now()->sub(new DateInterval('P7D')),
                CarbonImmutable::now(),
            )),
    );
});

it('seeds the window honestly: reconciled pairs count once, in-flight reservations count, old rows do not', function (): void {
    $account = AiProviderAccount::factory()->configured()->create();

    // 1. A succeeded record on its own: 100 in / 50 out.
    seedRecord($account, 100, 50);

    // 2. A reconciled reservation linked to its record: the reservation
    // counts at its consumed 200/100 and the linked record is excluded —
    // the same spend must not count from both sides.
    $linked = seedRecord($account, 200, 100);
    AiUsageReservation::factory()->reconciled()->create([
        'ai_provider_account_id' => $account->id,
        'ai_usage_record_id' => $linked->id,
        'reserved_input_tokens' => 220,
        'reserved_output_tokens' => 110,
        'reserved_total_tokens' => 330,
        'consumed_input_tokens' => 200,
        'consumed_output_tokens' => 100,
        'consumed_total_tokens' => 300,
    ]);

    // 3. An in-flight reservation with no record at all: 500/0.
    AiUsageReservation::factory()->started()->create([
        'ai_provider_account_id' => $account->id,
        'reserved_input_tokens' => 500,
        'reserved_output_tokens' => 0,
        'reserved_total_tokens' => 500,
    ]);

    // 4. A record outside the window: must not count.
    seedRecord($account, 999, 999, ['created_at' => now()->subDays(10)]);

    $this->travel(1)->minutes();

    $this->actingAs(anAiUsageAdmin())->get('/admin/ai-usage')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('totals.input_tokens', 800)
            ->where('totals.output_tokens', 150)
            ->where('totals.total_tokens', 950)
            ->where('perAccount.0.usage.total_tokens', 950),
    );
});

it('narrows the today window to the start of the day', function (): void {
    $account = AiProviderAccount::factory()->configured()->create();

    $this->travelTo(now()->setTime(10, 0));

    seedRecord($account, 40, 10);
    seedRecord($account, 7, 3, ['created_at' => now()->startOfDay()->subSecond()]);

    $this->travel(1)->minutes();

    $this->actingAs(anAiUsageAdmin())->get('/admin/ai-usage?window=today')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('window', 'today')
            ->where('totals.total_tokens', 50),
    );
});

it('answers an unknown window with a uniform 404', function (): void {
    $this->actingAs(anAiUsageAdmin())->get('/admin/ai-usage?window=forever')->assertNotFound();
});

it('states the cost in the configured currency and its minor unit', function (): void {
    config(['ai_usage.currency' => ['code' => 'USD', 'minor_unit' => 100]]);

    seedRecord(
        AiProviderAccount::factory()->configured()->create(),
        1000,
        500,
        ['estimated_cost_minor' => 325],
    );

    $this->travel(1)->minutes();

    $this->actingAs(anAiUsageAdmin())->get('/admin/ai-usage')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('currency', ['code' => 'USD', 'minor_unit' => 100])
            ->where('costMinor', 325)
            // 325 minor units = $3.25 — the page divides, the ledger never
            // stores a float.
            ->where('recent.0.estimated_cost_minor', 325),
    );
});

it('carries the recent ledger newest-first, failures included', function (): void {
    $account = AiProviderAccount::factory()->configured()->create();

    seedRecord($account, 10, 5, ['created_at' => now()->subMinutes(5)]);
    seedRecord($account, 0, 0, ['outcome' => 'provider_failed', 'status' => 'failed']);

    $this->actingAs(anAiUsageAdmin())->get('/admin/ai-usage')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('recent', 2)
            ->where('recent.0.outcome', 'provider_failed')
            ->where('recent.1.outcome', 'completed'),
    );
});
