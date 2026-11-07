<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Ai\SaveAiProviderAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAiProviderAccountRequest;
use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use App\Services\Ai\AiDriverCatalog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credential management for the Phase 10 AI subsystem.
 *
 * The one hard rule: a stored secret never crosses back to the browser.
 * Every row the panel ever sees goes through `toRow()`, which carries only
 * `maskedConfig()` and derived booleans — raw `->config` is server-only, and
 * the create/edit forms submit blank-or-sentinel for secret fields, which
 * `SaveAiProviderAccount` resolves server-side.
 *
 * Capability rows are read-only here: they are migration-seeded and
 * referenced as constants, and their `is_active` is a whole-capability kill
 * switch (`scopeUsable`), not a per-account toggle — surfacing it next to an
 * account's own switch would invite turning off the platform's moderation
 * when one credential is rotated.
 */
class AiAccountsController extends Controller
{
    public function __construct(private readonly SaveAiProviderAccount $save) {}

    public function index(): Response
    {
        $accounts = AiProviderAccount::query()
            ->with('capability')
            ->orderBy('ai_capability_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Admin/AiAccounts', [
            'accounts' => $accounts->map(fn (AiProviderAccount $account): array => $this->toRow($account))->values(),
            'capabilities' => AiCapability::query()->orderBy('id')->get(['id', 'key', 'label'])->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/AiAccounts/Create', [
            'account' => null,
            'capabilities' => AiCapability::query()->orderBy('id')->get(['id', 'key', 'label'])->all(),
            'drivers' => $this->drivers(),
        ]);
    }

    public function store(SaveAiProviderAccountRequest $request): RedirectResponse
    {
        $account = $this->save->handle(new AiProviderAccount, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.ai_accounts.saved')]);

        return redirect()->route('admin.ai-accounts.edit', $account);
    }

    public function edit(AiProviderAccount $account): Response
    {
        return Inertia::render('Admin/AiAccounts/Edit', [
            'account' => $this->toRow($account),
            'capabilities' => AiCapability::query()->orderBy('id')->get(['id', 'key', 'label'])->all(),
            'drivers' => $this->drivers(),
        ]);
    }

    public function update(SaveAiProviderAccountRequest $request, AiProviderAccount $account): RedirectResponse
    {
        $this->save->handle($account, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.ai_accounts.saved')]);

        return back();
    }

    /**
     * Usage records carry no foreign key onto the account, so history
     * survives a deletion — the ledger is append-only, not the account list.
     */
    public function destroy(AiProviderAccount $account): RedirectResponse
    {
        $account->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.ai_accounts.deleted')]);

        return redirect()->route('admin.ai-accounts.index');
    }

    /**
     * The only shape an admin response may carry. No raw `config`, ever —
     * masked values plus the derived state the badges read.
     *
     * @return array<string, mixed>
     */
    private function toRow(AiProviderAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'capability' => [
                'id' => $account->ai_capability_id,
                'key' => $account->capability?->key,
                'label' => $account->capability?->label,
            ],
            'driver' => $account->driver,
            'driver_label' => AiDriverCatalog::labelFor((string) $account->driver),
            'model' => $account->model,
            'is_active' => $account->is_active,
            'sort_order' => $account->sort_order,
            'config' => $account->maskedConfig(),
            'is_configured' => $account->isConfigured(),
            'is_cooling_down' => $account->isCoolingDown(),
            'unavailable_until' => $account->unavailable_until?->toISOString(),
            'last_failure_reason' => $account->last_failure_reason,
            'last_succeeded_at' => $account->last_succeeded_at?->toISOString(),
            'input_token_limit' => $account->input_token_limit,
            'output_token_limit' => $account->output_token_limit,
            'total_token_limit' => $account->total_token_limit,
            'limit_period' => $account->limit_period?->value,
            'limit_timezone' => $account->limit_timezone,
            'input_token_price_per_million' => $account->input_token_price_per_million,
            'output_token_price_per_million' => $account->output_token_price_per_million,
        ];
    }

    /**
     * Driver metadata for the form: the select plus the per-driver config
     * fields, straight from the catalog so form, validation and
     * `isConfigured()` cannot drift.
     *
     * @return list<array<string, mixed>>
     */
    private function drivers(): array
    {
        return array_map(
            static fn (string $driver): array => [
                'key' => $driver,
                'label' => AiDriverCatalog::labelFor($driver),
                'fields' => AiDriverCatalog::fieldsFor($driver),
            ],
            AiDriverCatalog::drivers(),
        );
    }
}
