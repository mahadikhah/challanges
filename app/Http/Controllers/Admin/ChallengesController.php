<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Challenges\CancelChallenge;
use App\Enums\ChallengeStatus;
use App\Enums\Contracts\HasTranslatedLabel;
use App\Exceptions\ChallengeNotCancellable;
use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Challenge moderation.
 *
 * A read-mostly window onto every challenge on the platform, with one lever:
 * cancellation. The listing is deliberately thin — title, creator, population,
 * timeline, state — because moderation is about spotting what needs acting on,
 * not re-rendering what the Mini App already shows each participant.
 *
 * Cancelling goes through `CancelChallenge`, the same action a creator-side
 * surface will call, so the rules — one-way door, creator-or-admin, staggered
 * participant notification — cannot drift between surfaces.
 */
class ChallengesController extends Controller
{
    public function __construct(private readonly CancelChallenge $cancelChallenge) {}

    public function index(Request $request): Response
    {
        $status = ChallengeStatus::tryFrom((string) $request->query('status', ''));
        $search = trim((string) $request->query('q', ''));

        $page = Challenge::query()
            ->with('creator')
            ->withCount('participants')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
            // A deterministic newest-first, including for rows created within
            // the same second — a panel listing that reshuffles on refresh
            // reads as broken.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Challenges', [
            'challenges' => $this->rows($page),
            'nextPageUrl' => $page->nextPageUrl(),
            'filters' => [
                'status' => $status?->value,
                'q' => $search,
            ],
        ]);
    }

    public function show(Challenge $challenge): Response
    {
        $challenge->load('creator');

        $participants = $challenge->participants()
            ->with('user')
            ->orderBy('joined_at')
            ->get()
            ->map(fn ($participant): array => [
                'name' => $participant->user->first_name ?? $participant->user->name,
                'status' => $this->enumShape($participant->status),
                'streak' => $participant->current_streak,
                'longest_streak' => $participant->longest_streak,
                'freezes_used' => $participant->freezes_used,
                'freezes_total' => $participant->freezes_total,
                'joined_at' => $participant->joined_at->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Admin/Challenges/Show', [
            'challenge' => [
                'id' => $challenge->getKey(),
                'title' => $challenge->title,
                'description' => $challenge->description,
                'status' => $this->enumShape($challenge->status),
                'visibility' => $this->enumShape($challenge->visibility),
                'proof_type' => $this->enumShape($challenge->proof_type),
                'period_type' => $this->enumShape($challenge->period_type),
                'creator' => $challenge->creator->first_name ?? $challenge->creator->name,
                'starts_at' => $challenge->starts_at->toIso8601String(),
                'total_periods' => $challenge->total_periods,
                'timezone' => $challenge->timezone,
                'default_freezes' => $challenge->default_freezes,
                'announced_at' => optional($challenge->announced_at)->toIso8601String(),
                'cancellable' => ! $challenge->status->isTerminal(),
            ],
            'participants' => $participants,
        ]);
    }

    public function cancel(Request $request, Challenge $challenge): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        try {
            $this->cancelChallenge->handle($user, $challenge);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('admin.challenges.cancelled'),
            ]);
        } catch (ChallengeNotCancellable) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('admin.challenges.cancel_refused'),
            ]);
        }

        return back();
    }

    /**
     * @param  Paginator<int, Challenge>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(Paginator $page): array
    {
        $rows = [];

        foreach ($page->getCollection() as $challenge) {
            $rows[] = [
                'id' => $challenge->getKey(),
                'title' => $challenge->title,
                'status' => $this->enumShape($challenge->status),
                'proof_type' => $this->enumShape($challenge->proof_type),
                'period_type' => $this->enumShape($challenge->period_type),
                'creator' => $challenge->creator->first_name ?? $challenge->creator->name,
                'participants_count' => $challenge->participants_count,
                'total_periods' => $challenge->total_periods,
                'starts_at' => $challenge->starts_at->toIso8601String(),
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
