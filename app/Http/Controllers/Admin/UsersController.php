<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Coins\AdjustUserCoins;
use App\Enums\Contracts\HasTranslatedLabel;
use App\Exceptions\InsufficientCoinsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustCoinsRequest;
use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The support desk: who is on the platform, what their coins look like, and
 * the one lever an operator needs — moving coins by hand.
 *
 * The balance shown is what the ledger reports (`balanceFor`), never a column
 * on the user, and the detail page carries the drift between the cached
 * running total and the entries themselves so a corrupt ledger is visible from
 * the panel rather than discovered during a dispute. Adjustments go through
 * `AdjustUserCoins`, which routes them into `CoinLedger` with the user's row
 * lock and an idempotency key — the panel gets no private path to coins, and
 * "every coin mutation goes through the ledger" stays literally true.
 */
class UsersController extends Controller
{
    public function __construct(
        private readonly CoinLedger $ledger,
        private readonly AdjustUserCoins $adjust,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $page = User::query()
            ->when($search !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('telegram_username', 'like', "%{$search}%")
                    // A bare number reads as a Telegram id — the one thing
                    // support reliably has in hand when a user writes in.
                    ->when(ctype_digit($search), fn ($q) => $q->orWhere('platform_user_id', (int) $search)),
            ))
            // Deterministic newest-first, including for rows created within
            // the same second.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Users', [
            'users' => $this->rows($page),
            'nextPageUrl' => $page->nextPageUrl(),
            'q' => $search,
        ]);
    }

    public function show(User $user): Response
    {
        $transactions = $user->coinTransactions()
            ->limit(20)
            ->get();

        $rows = [];

        foreach ($transactions as $transaction) {
            $rows[] = [
                'when' => $transaction->created_at?->toIso8601String(),
                'reason' => $this->enumShape($transaction->reason),
                'amount' => $transaction->amount,
                'balance_after' => $transaction->balance_after,
            ];
        }

        return Inertia::render('Admin/Users/Show', [
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->first_name ?? $user->name,
                'platform_user_id' => $user->platform_user_id,
                'telegram_username' => $user->telegram_username,
                'locale' => $user->locale,
                'is_admin' => $user->is_admin,
                'joined_at' => $user->created_at?->toIso8601String(),
            ],
            'balance' => $this->ledger->balanceFor($user),
            'drift' => $this->ledger->drift($user),
            'transactions' => $rows,
        ]);
    }

    public function coins(AdjustCoinsRequest $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        abort_unless($admin instanceof User, 403);

        $amount = (int) $request->validated('amount');

        try {
            $request->validated('direction') === 'debit'
                ? $this->adjust->debit($admin, $user, $amount)
                : $this->adjust->credit($admin, $user, $amount);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('admin.users.adjusted'),
            ]);
        } catch (InsufficientCoinsException) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('admin.users.refused_insufficient'),
            ]);
        }

        return back();
    }

    /**
     * @param  Paginator<int, User>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(Paginator $page): array
    {
        $rows = [];

        foreach ($page->getCollection() as $user) {
            $rows[] = [
                'id' => $user->getKey(),
                'name' => $user->first_name ?? $user->name,
                'platform_user_id' => $user->platform_user_id,
                'telegram_username' => $user->telegram_username,
                'is_admin' => $user->is_admin,
                'balance' => $this->ledger->balanceFor($user),
            ];
        }

        return $rows;
    }

    /**
     * @param  \BackedEnum&HasTranslatedLabel  $case
     * @return array{value: string, label: string}
     */
    private function enumShape($case): array
    {
        return [
            'value' => (string) $case->value,
            'label' => $case->label(),
        ];
    }
}
