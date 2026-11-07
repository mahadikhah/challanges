<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Contracts\HasTranslatedLabel;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use Illuminate\Pagination\Paginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The invite ledger, read-only.
 *
 * Whether a code earned its inviter coins is already settled data — the
 * `ClaimInvite` action decided it at the invited user's first `/start`, keyed
 * on "brand-new to the bot" — so this view has no levers: an audit that could
 * be adjusted by hand would be an audit nobody could trust.
 */
class InvitesController extends Controller
{
    public function index(): Response
    {
        $page = Invite::query()
            ->with(['inviter', 'invitedUser'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Invites', [
            'invites' => $this->rows($page),
            'nextPageUrl' => $page->nextPageUrl(),
        ]);
    }

    /**
     * @param  Paginator<int, Invite>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(Paginator $page): array
    {
        $rows = [];

        foreach ($page->getCollection() as $invite) {
            $invited = $invite->invitedUser;

            $rows[] = [
                'id' => $invite->getKey(),
                'code' => $invite->code,
                'inviter' => $invite->inviter->first_name ?? $invite->inviter->name,
                'invited' => $invited === null ? null : ($invited->first_name ?? $invited->name),
                'status' => $this->enumShape($invite->status),
                'credited_at' => $invite->credited_at?->toIso8601String(),
                'created_at' => $invite->created_at?->toIso8601String(),
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
