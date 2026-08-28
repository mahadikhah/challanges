<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CheckIns\OverrideCheckInVerdict;
use App\Actions\CheckIns\ReviewCheckIn;
use App\Enums\AiDecisionOutcome;
use App\Enums\CheckInStatus;
use App\Exceptions\CheckInRejectedException;
use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\User;
use App\Services\Telegram\NotifyCheckInVerdict;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * The image-proof review queue.
 *
 * A photo that arrives for an `image_approval` challenge sits in `Submitted`
 * until its creator — or, when the creator has gone quiet, a platform admin —
 * decides it. This is the panel's window onto that queue, and it deliberately
 * contains no rules of its own: every verdict goes through `ReviewCheckIn`,
 * the same action the bot's inline buttons call, so a photo cannot be approved
 * here in a way the bot would refuse.
 *
 * The photos themselves are served from a gated route rather than linked from
 * the disk: proofs are private by default, and an admin URL is the only door
 * they should ever have.
 *
 * `ReviewCheckIn` and `NotifyCheckInVerdict` are method-injected, not
 * constructor-injected: both eventually hold the Bot API client, whose binding
 * refuses to build without `TELEGRAM_BOT_TOKEN`. Reading the queue has nothing
 * to do with Telegram, so a browse of this page must not need a token — only
 * the routes that can actually send do.
 */
class ReviewQueueController extends Controller
{
    public function index(): InertiaResponse
    {
        $queue = CheckIn::query()
            ->where('status', CheckInStatus::Submitted)
            ->with(['participant.user', 'participant.challenge', 'period', 'aiDecision'])
            ->orderBy('submitted_at')
            ->limit(100)
            ->get()
            ->map(fn (CheckIn $checkIn): array => $this->row($checkIn))
            ->all();

        return Inertia::render('Admin/Reviews', [
            'reviews' => $queue,
            'settled' => $this->settledByAi(),
        ]);
    }

    /**
     * Decisions the AI took on its own, newest first — the override surface.
     *
     * A row leaves this list the moment a human touches it (`reviewed_by` is
     * set) or the participant resubmits over a rejection (the row returns to
     * `Submitted` and back to the pending queue above).
     *
     * @return list<array<string, mixed>>
     */
    private function settledByAi(): array
    {
        $rows = CheckIn::query()
            ->whereIn('status', [CheckInStatus::Approved, CheckInStatus::Rejected])
            ->whereNull('reviewed_by')
            ->whereHas('aiDecision', fn (Builder $query): Builder => $query->where(
                'outcome',
                AiDecisionOutcome::Applied->value,
            ))
            ->with(['participant.user', 'participant.challenge', 'period', 'aiDecision'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (CheckIn $checkIn): array => $this->row($checkIn))
            ->all();

        return array_values($rows);
    }

    /**
     * One queue card's payload, shared by the pending and AI-settled lists.
     *
     * @return array<string, mixed>
     */
    private function row(CheckIn $checkIn): array
    {
        return [
            'id' => $checkIn->getKey(),
            'challenge' => $checkIn->participant->challenge->title,
            'participant' => $this->participantName($checkIn),
            'period' => $checkIn->period->index + 1,
            'total_periods' => $checkIn->participant->challenge->total_periods,
            'submitted_at' => optional($checkIn->submitted_at)->toIso8601String(),
            'proof_url' => route('admin.reviews.proof', $checkIn->getKey()),
            'ai_decision' => $this->aiDecision($checkIn),
            'status' => $checkIn->status->value,
        ];
    }

    /**
     * The latest AI call on this submission, if any, for the queue card.
     *
     * Display data only: the decision and its reason are shown to the admin,
     * and nothing else in the panel reads them.
     *
     * @return array<string, mixed>|null
     */
    private function aiDecision(CheckIn $checkIn): ?array
    {
        $decision = $checkIn->aiDecision;

        if ($decision === null) {
            return null;
        }

        return [
            'approved' => $decision->approved,
            'confidence' => $decision->confidence,
            'reason' => $decision->reason,
            'model' => $decision->model,
            'fell_back' => $decision->outcome === AiDecisionOutcome::FellBack,
        ];
    }

    /**
     * One stored proof, streamed to an authenticated admin only.
     */
    public function proof(CheckIn $checkIn): BinaryFileResponse|Response
    {
        $path = $checkIn->proof_path;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    public function approve(
        Request $request,
        CheckIn $checkIn,
        ReviewCheckIn $review,
        NotifyCheckInVerdict $notify,
    ): RedirectResponse {
        return $this->verdict($request, $checkIn, $review, $notify, approved: true);
    }

    public function reject(
        Request $request,
        CheckIn $checkIn,
        ReviewCheckIn $review,
        NotifyCheckInVerdict $notify,
    ): RedirectResponse {
        return $this->verdict($request, $checkIn, $review, $notify, approved: false);
    }

    /**
     * Flip a decision that already took — an AI verdict a human disagrees with.
     *
     * Reverses the settlement (restoring the streak arithmetic) and lands the
     * admin's verdict through the ordinary review path, so the override is
     * two auditable steps rather than an opaque status rewrite.
     */
    public function override(
        Request $request,
        CheckIn $checkIn,
        string $verdict,
        OverrideCheckInVerdict $override,
        NotifyCheckInVerdict $notify,
    ): RedirectResponse {
        $approved = $verdict === 'approve';
        $admin = $this->theAdmin($request);

        try {
            $settled = $override->handle($admin, $checkIn, $approved);
        } catch (CheckInRejectedException $refused) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __("admin.reviews.refused.{$refused->reason->value}"),
            ]);

            return back();
        }

        try {
            $approved ? $notify->approved($settled) : $notify->rejected($settled);
        } catch (TelegramSDKException) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('admin.reviews.notify_failed'),
            ]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.reviews.overridden'),
        ]);

        return back();
    }

    /**
     * One verdict, one flash, one redirect — whatever the action says.
     *
     * A refusal is a normal outcome here: the queue is a snapshot, and the
     * rollover may have settled a row between the page load and the click. The
     * admin gets the reason as a toast, the same way the bot's creator gets it
     * as a reply.
     */
    private function verdict(
        Request $request,
        CheckIn $checkIn,
        ReviewCheckIn $review,
        NotifyCheckInVerdict $notify,
        bool $approved,
    ): RedirectResponse {
        $admin = $this->theAdmin($request);

        try {
            $settled = $approved
                ? $review->approve($admin, $checkIn)
                : $review->reject($admin, $checkIn);
        } catch (CheckInRejectedException $refused) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __("admin.reviews.refused.{$refused->reason->value}"),
            ]);

            return back();
        }

        // The verdict has landed; telling the participant is the remaining
        // step. If that cannot happen — no bot token on this box, Telegram
        // down — the admin needs to know the notification, not the verdict,
        // is what failed.
        try {
            $approved ? $notify->approved($settled) : $notify->rejected($settled);
        } catch (TelegramSDKException) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('admin.reviews.notify_failed'),
            ]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.reviews.'.($approved ? 'approved' : 'rejected')),
        ]);

        return back();
    }

    /**
     * `auth` + `EnsureUserIsAdmin` have both run by now; this is the typed
     * narrowing, not a second permission check.
     */
    private function theAdmin(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * What the queue calls a participant: their Telegram first name when they
     * have one, falling back to whatever name the row carries.
     */
    private function participantName(CheckIn $checkIn): string
    {
        $user = $checkIn->participant->user;

        return $user->first_name ?? $user->name;
    }
}
