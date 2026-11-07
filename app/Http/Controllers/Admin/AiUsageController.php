<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiUsageRecordStatus;
use App\Http\Controllers\Controller;
use App\Models\AiProviderAccount;
use App\Models\AiUsageRecord;
use App\Services\Ai\AiQuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the AI tokens went — and what they cost.
 *
 * Every token total on this page comes from `AiQuotaService::consumedBetween`
 * — the same extraction the budget gate uses — never a fresh SUM over
 * `ai_usage_records`: the naive SUM double-counts reconciled reservations
 * and misses in-flight ones. The per-account loop is O(N) calls to that one
 * method; if the account count ever makes that hurt, the escape hatch is a
 * grouped variant inside the service (so the gate and the page still cannot
 * disagree), not a controller-side query.
 */
class AiUsageController extends Controller
{
    /** @var list<string> */
    private const WINDOWS = ['today', '7d', '30d'];

    public function __construct(private readonly AiQuotaService $quota) {}

    public function index(Request $request): Response
    {
        $window = (string) $request->query('window', '7d');

        abort_unless(in_array($window, self::WINDOWS, true), 404);

        $endsAt = CarbonImmutable::now();
        $startsAt = match ($window) {
            'today' => $endsAt->startOfDay(),
            '7d' => $endsAt->sub(new \DateInterval('P7D')),
            '30d' => $endsAt->sub(new \DateInterval('P30D')),
        };

        $totals = $this->quota->consumedBetween($startsAt, $endsAt);

        $accounts = AiProviderAccount::query()
            ->orderBy('ai_capability_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // O(N) reads of the one honest counter — see the class docblock.
        $perAccount = $accounts->map(fn (AiProviderAccount $account): array => [
            'id' => $account->id,
            'name' => $account->name,
            'driver' => $account->driver,
            'is_active' => $account->is_active,
            'usage' => $this->quota->consumedBetween($startsAt, $endsAt, (int) $account->id),
        ]);

        $recent = AiUsageRecord::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('Admin/AiUsage', [
            'window' => $window,
            'totals' => $totals,
            'currency' => config('ai_usage.currency'),
            // Cost is not part of the gate's accounting, so it is the one
            // figure read straight off the ledger rows — still in minor
            // units; the page divides by the configured minor unit.
            'costMinor' => (int) AiUsageRecord::query()
                ->where('created_at', '>=', $startsAt->utc())
                ->where('created_at', '<', $endsAt->utc())
                ->whereIn('status', [AiUsageRecordStatus::Succeeded, AiUsageRecordStatus::NoUsage])
                ->sum('estimated_cost_minor'),
            'perAccount' => $perAccount->values(),
            'recent' => $recent->map(fn (AiUsageRecord $record): array => [
                'operation' => $record->operation,
                'outcome' => $record->outcome->value,
                'driver' => $record->driver,
                'model' => $record->model,
                'input_tokens' => $record->input_tokens,
                'output_tokens' => $record->output_tokens,
                'total_tokens' => $record->total_tokens,
                'estimated_cost_minor' => $record->estimated_cost_minor,
                'created_at' => $record->created_at?->toISOString(),
            ])->values(),
        ]);
    }
}
